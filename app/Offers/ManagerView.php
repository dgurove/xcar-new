<?php

namespace App\Offers;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Cars\OwnerContacts;
use App\Media\DocName;
use App\Media\ForManagers;
use App\Offers\Actions\ShowToManager;
use App\Support\Ago;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Что видит менеджер сделки — одна дверь (25.11.2026, владелец: «„в сделку“ и „менеджеру“ должно быть одно и то же; то, что из письма
 * парсится, — в админке встаёт само, а что мы у менеджера отображаем — сотрудникам видеть и решать; и после „показать“ — окошко, где
 * видно, что он видит, а что нет, с возможностью убирать, добавлять»). Пункты — живые данные сделки, а не снимки:
 * - **контакт для получения** — контакт владельца из карточки (`OwnerContacts`), виден с `deals.manager_sees.contact` (когда показали);
 * - **документы машины** — флагом файла (`ForManagers`): паспорт, СТС, ПТС, счёт поставщика, ДКП от страховой, договор комиссии…;
 * - **наш счёт и договор** — `shown_at` (`ShowToManager`, «Отозвать»).
 * Сообщения от нас (тексты показов, `Disclosure`) — история, правятся и удаляются (`EditDisclosure`). Изменения — `Actions\SetManagerView`,
 * окно — `x-deal.manager-view`. Менеджер берёт видимое отсюда же (`Handover`, «Документы»).
 */
final class ManagerView
{
    public const CONTACT = 'contact';

    /** Видит ли менеджер пункт без файла (`contact`). */
    public static function sees(Deal $deal, string $key): bool
    {
        return isset(($deal->manager_sees ?? [])[$key]);
    }

    /** С какого момента видит. */
    public static function since(Deal $deal, string $key): ?Carbon
    {
        $at = ($deal->manager_sees ?? [])[$key] ?? null;

        return $at ? Carbon::parse($at) : null;
    }

    /** Контакт для получения, что видит менеджер: карточка, если показан. @return list<array{name: ?string, phone: ?string}> */
    public static function contacts(Deal $deal): array
    {
        return self::sees($deal, self::CONTACT) ? OwnerContacts::of($deal->offer) : [];
    }

    /** Вид документа словом — по пометкам сделки и `OwnerDocs`. */
    public static function docWord(Media $m): ?string
    {
        return match (true) {
            (bool) $m->getCustomProperty(SupplierInvoice::PROP) => 'Счёт поставщика',
            (bool) $m->getCustomProperty(SupplierContract::PROP) => 'ДКП от страховой',
            default => match (OwnerDocs::kind($m)) {
                OwnerDocs::PASSPORT => 'Паспорт собственника',
                OwnerDocs::STS => 'СТС',
                OwnerDocs::PTS => 'ПТС',
                default => preg_match('/договор\W*комисс/iu', (string) $m->name) ? 'Договор комиссии' : null,
            },
        };
    }

    /**
     * Все пункты сделки, что можно показать менеджеру, группами `contact`, `docs`, `ours`.
     *
     * @return list<array{key: string, group: string, label: string, sub: ?string, visible: bool, can: bool, media: ?Media, letter: ?int, icon: ?string, file: ?string, edit: bool}>
     */
    public static function items(Deal $deal): array
    {
        $offer = $deal->offer;
        $out = [];
        $contacts = OwnerContacts::of($offer);
        $first = $contacts[0] ?? null;
        $phones = implode(', ', array_filter(array_column($contacts, 'phone')));
        $out[] = ['key' => self::CONTACT, 'group' => 'contact', 'icon' => 'user', 'file' => null, 'media' => null, 'letter' => null, 'edit' => true,
            'label' => $first ? ($first['name'] ?: $first['phone']) : 'Контакт не указан',
            'sub' => $first ? trim('Контакт для получения'.($phones !== '' && $first['name'] ? ', '.$phones : '').(count($contacts) > 1 ? ', ещё '.(count($contacts) - 1) : '').($offer->inspection_address ? ', '.$offer->inspection_address : ''), ', ') : 'впишите, чтобы показать',
            'visible' => self::sees($deal, self::CONTACT), 'can' => $first !== null];
        foreach ($offer->getMedia('papers')->sortByDesc('id') as $m) {
            $word = self::docWord($m);
            $letter = (int) $m->getCustomProperty('letter') ?: null;
            $name = pathinfo(DocName::of($m), PATHINFO_FILENAME);
            // Вид словом — главной строкой («Паспорт собственника»), имя файла — второй, если оно другое («Паспорт1»).
            $same = $word && mb_strtolower($name) === mb_strtolower($word);
            $out[] = ['key' => 'file:'.$m->id, 'group' => 'docs', 'icon' => null, 'file' => DocName::of($m), 'media' => $m, 'letter' => $letter, 'edit' => false,
                'label' => $word ?? $name,
                'sub' => implode(', ', array_filter([$word && ! $same ? $name : null, ($letter ? 'из письма ' : 'добавлен ').Ago::day($m->created_at)])),
                'visible' => ForManagers::is($m), 'can' => true];
        }
        if ($deal->buyer_id) {
            $invoices = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->orderBy('id')->get();
            foreach ($invoices as $i) {
                $out[] = ['key' => 'invoice:'.$i->id, 'group' => 'ours', 'icon' => null, 'file' => 'invoice.pdf', 'media' => null, 'letter' => null, 'edit' => false,
                    'label' => ($i->kind === \App\Billing\ChargeKind::Selection ? 'Счёт за подбор' : 'Счёт за ТС').($i->code ? ' № '.$i->code : ''),
                    'sub' => $i->shown_at ? 'отправлен '.Ago::day($i->shown_at) : 'не отправлен',
                    'visible' => $i->shown_at !== null, 'can' => $i->shown_at ? ShowToManager::canRecallInvoice($i) : true];
            }
            if ($deal->hasContract()) {
                $c = DealContract::for($deal);
                $out[] = ['key' => 'contract', 'group' => 'ours', 'icon' => null, 'file' => 'dkp.pdf', 'media' => null, 'letter' => null, 'edit' => false,
                    'label' => 'Договор купли-продажи', 'sub' => $c->shown_at ? 'отправлен '.Ago::day($c->shown_at) : ($c->sendable() ? 'не отправлен' : 'не готов: '.implode(', ', $c->missing())),
                    'visible' => $c->shown_at !== null, 'can' => $c->shown_at ? ShowToManager::canRecallContract($deal) : $c->sendable()];
            }
        }

        return $out;
    }

    /** Коротко, что видит: «контакт, паспорт, СТС, счёт» — строке в ходе сделки. */
    public static function summary(Deal $deal): string
    {
        $seen = array_filter(self::items($deal), fn ($i) => $i['visible']);
        $words = array_map(fn ($i) => match (true) {
            $i['key'] === self::CONTACT => 'контакт',
            $i['group'] === 'ours' => $i['key'] === 'contract' ? 'договор' : 'счёт',
            // «Паспорт собственника» → «паспорт собственника», «СТС» — как есть.
            default => preg_match('/^\p{Lu}\p{Ll}/u', $i['label']) ? mb_strtolower(mb_substr($i['label'], 0, 1)).mb_substr($i['label'], 1) : $i['label'],
        }, $seen);

        return implode(', ', array_slice(array_unique($words), 0, 4)).(count(array_unique($words)) > 4 ? ' и ещё '.(count(array_unique($words)) - 4) : '');
    }
}
