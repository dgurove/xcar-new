<?php

namespace App\Offers;

use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Оригиналы документов — последний шаг сделки (26.11.2026, владелец: «сделка не закрыта, пока оригиналы документов не получили,
 * только я не знаю, где они у кого»). Ведёт Лиза. Что за документы — по сделке (`kit`):
 *
 * - **Совкомбанк** (комиссия; Катя, 26.11.2026: «их оригиналы должны попасть К НАМ, не в Совком»): при вывозе страхователь
 *   подписывает договор комиссии и акт приёма-передачи — их оригиналы от исполнителя вывоза к нам; редко у собственника уже по
 *   четыре экземпляра соглашения и акта — тогда к нам только доверенность (меню «⋯» документа, `switchKind`). После продажи
 *   прежнему владельцу шлём ДКП СДЭКом, чтобы снял машину с учёта, — обязательно. Подписанный ДКП от менеджера — хватит скана,
 *   его берёт шаг «Подписанный договор».
 * - **Р.О.С. или ПРАЙМ у остальных** (владелец, 26.11.2026: «нужны оригиналы ДКП, если договор не на покупателя, а на прайм или на
 *   рос; когда счёт на рос — все пакеты документов, сканы и оригиналы, рос как покупатель выступает»): ДКП и акт с поставщиком,
 *   ДКП и акт с покупателем (оба — у менеджера), счёт-фактура или УПД поставщика (продаёт страховая, `DealFormat::Supplier`; у Т
 *   продаёт собственник — её нет). У каждого — скан и оригинал (`needs_scan`). Покупатель платит страховой сам — шага нет
 *   (оригиналы ездят между офисами Альфы, маршрут ведёт в «Оригиналы» только веткой `ros`).
 *
 * Где документ — кнопками следующего шага под документом (`x-deal.originals`, `Original::actions`, `update`; трек СДЭК — полем
 * в строке), редкое — меню «⋯» документа; скан — «Скан» там же (`attachScan`); у каждого документа история с датами
 * (`Original::note`). Ждём менеджера — при входе в шаг ему сообщение в чат (`nudge`), повтор — из меню «⋯». Всё на месте (`Original::settled`) — шаг проходит
 * сам «Оригиналы на месте» → «Сделка закрыта» (`advance`). Вкладка «Оригиналы» (`DealTabs`): наше дело — `todo`, иначе ждём
 * менеджера или документы в пути (`waits`).
 */
final class Originals
{
    public const STAGE = 'Оригиналы';

    public const DONE = 'Оригиналы на месте';

    /** Куда — словами шторки «+ Документ». */
    public const DESTINATIONS = ['us' => 'Нам', 'owner' => 'Прежнему владельцу', 'supplier' => 'Поставщику'];

    /** Что подписал страхователь у Совкома — первым документом (меню «⋯» документа). */
    public const SIGNED = ['commission' => 'Договор комиссии и акт приёма-передачи', 'proxy' => 'Доверенность'];

    public static function applies(?Stage $stage): bool
    {
        return $stage?->name === self::STAGE;
    }

    /**
     * Документы сделки по умолчанию: вид, что, куда, нужен ли скан.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function kit(Deal $deal): array
    {
        if ($deal->isCommission()) {
            return [
                ['commission', self::SIGNED['commission'], 'us', false],
                ['dkp_owner', 'ДКП прежнему владельцу', 'owner', false],
            ];
        }
        // Покупатель платит страховой или собственнику сам — оригиналы не наши (сделку поставили на шаг руками): шаг пройдёт сам.
        if (! $deal->schemeOf()->paysCar()) {
            return [];
        }
        $kit = [
            ['supplier_dkp', 'ДКП и акт с поставщиком', 'us', true],
            ['buyer_dkp', 'ДКП и акт с покупателем', 'us', true],
        ];
        if ($deal->offer?->vendor?->deal_format === DealFormat::Supplier) {
            $kit[] = ['supplier_invoice', 'Счёт-фактура или УПД поставщика', 'us', true];
        }

        return $kit;
    }

    /** Скан нужен всему пакету Р.О.С. и ПРАЙМ, и добавленному «+ Документ» тоже. */
    public static function scans(Deal $deal): bool
    {
        return ! $deal->isCommission();
    }

    /** Документы сделки — только прочитать (экраны и раскладка вкладок не пишут; заводит вход в шаг, `ensure`). @return Collection<int, Original> */
    public static function of(Deal $deal): Collection
    {
        return Original::with('media')->where('deal_id', $deal->id)->orderBy('id')->get()->each->setRelation('deal', $deal);
    }

    /** Документы по умолчанию — один раз на сделку. Да — завели сейчас (первый вход в шаг). */
    public static function ensure(Deal $deal): bool
    {
        return DB::transaction(function () use ($deal) {
            Deal::whereKey($deal->id)->lockForUpdate()->first();
            if (Original::where('deal_id', $deal->id)->exists()) {
                return false;
            }
            foreach (self::kit($deal) as [$kind, $title, $to, $scan]) {
                Original::create(['deal_id' => $deal->id, 'kind' => $kind, 'title' => $title, 'to' => $to, 'needs_scan' => $scan]);
            }

            return true;
        });
    }

    /** Наше дело на шаге (`DealTabs`): отправить, получить у исполнителя, приложить сканы; иначе ждём (null). */
    public static function todo(Deal $deal, ?\Carbon\Carbon $since = null, ?Collection $items = null): ?array
    {
        $items ??= self::of($deal);
        $button = ['label' => 'Открыть', 'href' => '/offers/'.$deal->offer?->number];
        // Документов ещё нет (сделка встала на шаг раньше, чем они появились) — их завести: тоже наше дело.
        if ($items->isEmpty()) {
            return self::kit($deal) === [] ? null : ['key' => 'originals-open', 'title' => 'Завести документы', 'rank' => 2, 'since' => $since, 'button' => $button];
        }
        // Ключ — само дело: вкладка группирует по нему («Отправить ДКП прежнему владельцу», «Приложить сканы» — порознь). Дело
        // документа считается один раз (ревью 26.11.2026: `todo()` ходит за сканами).
        $todos = $items->map(fn (Original $o) => ['todo' => $o->todo(), 'item' => $o]);
        $todo = fn (string $key) => $todos->firstWhere('todo', $key)['item'] ?? null;
        $send = $todo('send');
        [$key, $title] = match (true) {
            $send !== null => $send->kind === 'dkp_owner' ? ['originals-owner', 'Отправить ДКП прежнему владельцу'] : ['originals-send', 'Отправить оригиналы'],
            $todo('executor') !== null => ['originals-executor', 'Получить оригиналы у исполнителя'],
            $todo('delivery') !== null => ['originals-delivery', 'Проверить доставку'],
            $todo('scan') !== null => ['originals-scan', 'Приложить сканы'],
            default => [null, null],
        };

        return $key ? ['key' => $key, 'title' => $title, 'rank' => 2, 'since' => $since, 'button' => $button] : null;
    }

    /** Кого ждём, когда нашего дела нет (группа ожидания вкладки, `DealTabs::WAITS`): документ у менеджера — его, иначе в пути. */
    public static function waits(Deal $deal): string
    {
        return self::of($deal)->contains->waitsManager() ? 'manager' : 'originals';
    }

    /** Менеджер ещё должен прислать документ (`Deal::doneForManager`: сделка для него не закончена). */
    public static function managerOwes(Deal $deal): bool
    {
        return Original::where('deal_id', $deal->id)->where('state', 'waiting')->whereIn('kind', array_keys(array_filter(Original::FROM, fn ($f) => $f === 'manager')))->exists();
    }

    /** Где документ сейчас и трек СДЭК (кнопки под документом и меню «⋯»). Всё на месте — шаг проходит сам. */
    /** $kind — у Совкома что подписали (`SIGNED`): меняется вместе с остальным или никак, если проверка не прошла. */
    public static function update(Original $item, string $state, ?string $track, User $by, ?string $kind = null): Original
    {
        // Под замком сделки (26.11.2026, ревью): двое отмечают последние документы разом — шаг проходит один раз.
        return DB::transaction(function () use ($item, $state, $track, $by, $kind) {
            Deal::whereKey($item->deal_id)->lockForUpdate()->first();

            return self::write($item->fresh(), $state, $track, $by, $kind);
        });
    }

    private static function write(Original $item, string $state, ?string $track, User $by, ?string $kind = null): Original
    {
        if (! isset($item->choices()[$state])) {
            throw ValidationException::withMessages(['state' => 'Выберите, где документ']);
        }
        $track = $track !== null ? strtoupper(preg_replace('/\s+/u', '', $track)) : null;
        if ($state === 'sent' && ! preg_match('/^[A-Z0-9-]{6,40}$/', (string) $track)) {
            throw ValidationException::withMessages(['track' => 'Трек-номер СДЭК']);
        }
        if ($kind !== null) {
            self::switchKind($item, $kind, $by);
        }
        $was = $item->place();
        $placed = (clone $item)->fill(['state' => $state])->placed();
        if ($state !== $item->state || ($state === 'sent' && $track !== $item->track)) {
            $item->note(['state' => $state] + ($state === 'sent' ? ['track' => $track] : []), $by->id);
        }
        $item->update([
            'state' => $state,
            'track' => $track ?: $item->track,
            'sent_at' => $state === 'sent' ? ($item->sent_at ?? now()) : $item->sent_at,
            'done_at' => $placed ? ($item->done_at ?? now()) : null,
            'updated_by' => $by->id,
        ]);
        $deal = $item->deal;
        if ($was !== $item->place()) {
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => $item->title.': '.$was.' → '.$item->place().($item->track && $state === 'sent' ? ', трек '.$item->track : '')]);
        }
        // Дошло то, что было у менеджера, — для него сделка, может быть, закончена (`Deal::doneForManager`).
        if ($item->from() === 'manager') {
            DB::afterCommit(fn () => rescue(fn () => Deal::find($deal->id)?->syncManagerDone(), report: true));
        }
        self::advance($deal, $by);

        return $item;
    }

    /** Совком, редкий вариант: вместо договора комиссии с актом — доверенность (и обратно). Зовёт `write` под замком. */
    private static function switchKind(Original $item, string $kind, User $by): void
    {
        abort_unless(isset(self::SIGNED[$kind], self::SIGNED[$item->kind]), 422);
        if ($item->kind === $kind) {
            return;
        }
        $was = $item->title;
        $item->update(['kind' => $kind, 'title' => self::SIGNED[$kind], 'updated_by' => $by->id]);
        $item->deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Оригиналы: «'.self::SIGNED[$kind].'» вместо «'.$was.'»']);
    }

    /** Скан документа («Скан» под документом, `x-ui.photo-pdf`: фото и файлы — одним). Всё на месте — шаг проходит сам. */
    public static function attachScan(Original $item, UploadedFile $file, User $by): void
    {
        // Файл — до транзакции (ревью 26.11.2026): откат перехода шага не оставит на диске файл без записи; скан приложен в
        // любом случае, а шаг пройдёт, когда пройдёт.
        $item->addMedia($file)->usingFileName($file->getClientOriginalName())->toMediaCollection('scan');
        DB::transaction(function () use ($item, $by) {
            Deal::whereKey($item->deal_id)->lockForUpdate()->first();
            $item->note(['scan' => true], $by->id);
            $item->update(['updated_by' => $by->id]);
            $item->deal->offer?->log(OfferEventType::Note, $by, ['text' => $item->title.': приложен скан']);
            self::advance($item->deal, $by);
        });
    }

    public static function removeScan(Original $item, int $media, User $by): void
    {
        $file = $item->getMedia('scan')->firstWhere('id', $media) ?? abort(404);
        $file->delete();
        $item->note(['scan' => false], $by->id);
        $item->update(['updated_by' => $by->id]);
        $item->deal->offer?->log(OfferEventType::Note, $by, ['text' => $item->title.': скан убран']);
    }

    /** «+ Документ»: доп. соглашение и прочее, что тоже должно дойти. */
    public static function add(Deal $deal, string $title, string $to, User $by): Original
    {
        abort_unless(isset(self::DESTINATIONS[$to]), 422);
        self::ensure($deal);
        $item = Original::create(['deal_id' => $deal->id, 'kind' => 'extra', 'title' => $title, 'to' => $to, 'needs_scan' => self::scans($deal), 'updated_by' => $by->id]);
        $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Оригиналы: добавлен «'.$title.'»']);

        return $item;
    }

    /** Убрать добавленный руками (документы по умолчанию — только «Не нужен»). */
    public static function remove(Original $item, User $by): void
    {
        abort_unless($item->kind === 'extra', 422);
        DB::transaction(function () use ($item, $by) {
            $deal = Deal::whereKey($item->deal_id)->lockForUpdate()->firstOrFail();
            $item->delete();
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Оригиналы: убран «'.$item->title.'»']);
            self::advance($deal, $by);
        });
    }

    /** Всё на месте и сделка на шаге — «Оригиналы на месте» → «Сделка закрыта». Да — прошли. */
    public static function advance(Deal $deal, ?User $by = null): bool
    {
        $offer = $deal->offer?->fresh();
        $stage = $offer?->position(Track::Sale)?->stage?->loadMissing('exits');
        if (! $deal->isActive() || ! self::applies($stage)) {
            return false;
        }
        $items = self::of($deal);
        // Пусто — ждать нечего, только если и набор пуст (покупатель платит не нам); иначе документы ещё не завели.
        if (($items->isEmpty() && self::kit($deal) !== []) || $items->contains(fn (Original $o) => ! $o->settled())) {
            return false;
        }
        $exit = $stage->exits->firstWhere('label', self::DONE) ?? $stage->exits->first();
        if (! $exit) {
            return false;
        }
        app(TakeExit::class)($offer, $exit, Actor::Staff, $by);

        return true;
    }

    /**
     * Попросить менеджера прислать оригиналы, что у него, — сообщением в чат сделки (при входе в шаг сам, `OpenOriginals`, и
     * из меню «⋯» документа). Нечего просить — false.
     */
    public static function nudge(Deal $deal, ?User $by = null): bool
    {
        if (! $deal->buyer_id || ! $deal->isActive()) {
            return false;
        }
        $owed = self::of($deal)->filter->waitsManager();
        if ($owed->isEmpty()) {
            return false;
        }
        $noScans = $owed->contains(fn (Original $o) => $o->needs_scan && $o->scans()->isEmpty());
        $chat = app(\App\Chats\Actions\OpenChat::class)($deal->offer, $deal->buyer, greet: false);
        app(\App\Chats\Actions\PlatformMessage::class)($chat, 'Нужны оригиналы документов по '.$deal->offer->title().': '
            .$owed->pluck('title')->implode(', ').'. Отправьте их нам СДЭКом и напишите трек-номер в этот чат'
            .($noScans ? '. Сканы пришлите сюда же' : ''));
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Попросили менеджера прислать оригиналы: '.$owed->pluck('title')->implode(', ')]);

        return true;
    }
}
