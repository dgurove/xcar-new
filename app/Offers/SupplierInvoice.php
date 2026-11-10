<?php

namespace App\Offers;

use App\Media\ForManagers;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Счёт поставщика менеджеру (10.11.2026, владелец по 0790/046/11079/26: «прислали счёт, а как его показать менеджеру —
 * непонятно»). Альфа выставила счёт на покупателя Дениса Трофимова PDF-файлом «2026110917401420261109173917», письмо легло в
 * «Разобрать ответ», а кнопка «Счёт от поставщика получен» перевела сделку на «Оплатить страховой» — менеджер платить должен,
 * а счёта не видит. Где менеджер платит поставщику сам (`Deal::paysSupplierOn` этапа, куда ведёт выход), выход сотрудника
 * туда без счёта не проходит: счёт выбирают файлом (из письма, документов или новым), он открывается менеджеру и
 * называется «Счёт поставщика от …» (`mark`). На этапе оплаты без показанного счёта в CRM — красная строка с «Показать счёт».
 */
final class SupplierInvoice
{
    public const PROP = 'supplier_invoice';

    /** Выход сотрудника ведёт на оплату поставщику менеджером — без счёта поставщика его не пройти. */
    public static function needs(?Deal $deal, ?Outcome $exit): bool
    {
        return $deal !== null && $exit !== null && $exit->actor === Actor::Staff && $deal->buyer_id && $deal->paysSupplierOn($exit->to);
    }

    /** Этап, на котором менеджер платит поставщику по его счёту. */
    public static function onPay(?Deal $deal, ?Stage $stage): bool
    {
        return $deal !== null && $deal->buyer_id && $deal->paysSupplierOn($stage);
    }

    /** Показанные менеджеру счета поставщика, новые сверху. */
    public static function shown(Offer $offer): Collection
    {
        return $offer->getMedia('papers')->filter(fn (Media $m) => $m->getCustomProperty(self::PROP) && ForManagers::is($m))->sortByDesc('id')->values();
    }

    /** Из чего выбрать: документы (PDF и файлы из писем, без кадров лота), из писем — первыми, новые сверху. */
    public static function candidates(Offer $offer): Collection
    {
        return $offer->getMedia('papers')
            ->filter(fn (Media $m) => $m->getCustomProperty('letter') || $m->mime_type === 'application/pdf')
            ->sortBy([fn (Media $a, Media $b) => (int) (bool) $b->getCustomProperty('letter') <=> (int) (bool) $a->getCustomProperty('letter'), fn (Media $a, Media $b) => $b->id <=> $a->id])
            ->values();
    }

    /** Что отметить заранее: последний PDF из письма поставщика (Альфа шлёт счёт одним PDF), иначе ничего. */
    public static function guess(Collection $files): ?Media
    {
        return $files->first(fn (Media $m) => $m->getCustomProperty('letter') && $m->mime_type === 'application/pdf');
    }

    /** Открыть менеджеру как счёт: имя «Счёт поставщика от 09.11.2026» вместо номера со сканера. */
    public static function mark(Media $m): void
    {
        $m->name = 'Счёт поставщика от '.$m->created_at->timezone('Europe/Moscow')->format('d.m.Y');
        $m->setCustomProperty(self::PROP, true)->setCustomProperty('managers', true)->save();
    }

    /** Подпись строки выбора: «из письма 09.11», «загружен 09.11». */
    public static function origin(Media $m): string
    {
        return ($m->getCustomProperty('letter') ? 'из письма ' : 'добавлен ').$m->created_at->timezone('Europe/Moscow')->format('d.m');
    }
}
