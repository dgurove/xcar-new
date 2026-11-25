<?php

namespace App\Offers;

use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Оригиналы документов — последний шаг сделки Совкомбанка (26.11.2026, владелец: «сделка не закрыта, пока оригиналы документов не
 * получили, только я не знаю, где они у кого»; разбор писем: страхователь подписывает при вывозе договор комиссии и акт — их
 * оригиналы нужны Совкомбанку для выплаты; прежнему владельцу шлём ДКП СДЭКом, чтобы снял машину с учёта («клиент так и не
 * смог получить ДКП», «пришла оплата за платку»); подписанный покупателем ДКП менеджер возвращает нам). Ведёт Лиза.
 *
 * - Документы — `Original`: при входе в шаг три (`DEFAULTS`), «+ Документ» — доп. соглашение и прочее (`add`).
 * - Где сейчас и трек СДЭК — шторкой строки в шаге (`x-deal.originals`, `update`); напомнить менеджеру прислать ДКП — в чат.
 * - Всё на месте (`Original::settled`) — шаг проходит сам «Оригиналы на месте» → «Сделка закрыта» (`advance`).
 * - Вкладка «Оригиналы» (`DealTabs`): наше дело — отправить то, что у нас или ещё ждём от исполнителя (`todo`); остальное — ждём.
 */
final class Originals
{
    public const STAGE = 'Оригиналы';

    public const DONE = 'Оригиналы на месте';

    /** @var list<array{0: string, 1: string, 2: string}> вид, что, куда */
    public const DEFAULTS = [
        ['commission', 'Договор комиссии и акт приёма-передачи', 'supplier'],
        ['dkp_owner', 'Договор купли-продажи прежнему владельцу', 'owner'],
        ['dkp_back', 'Подписанный договор купли-продажи', 'us'],
    ];

    /** Куда — словами шторки «+ Документ». */
    public const DESTINATIONS = ['supplier' => 'Поставщику', 'owner' => 'Прежнему владельцу', 'us' => 'Нам'];

    public static function applies(?Stage $stage): bool
    {
        return $stage?->name === self::STAGE;
    }

    /** Документы сделки — только прочитать (экраны и раскладка вкладок не пишут; заводит вход в шаг, `ensure`). @return Collection<int, Original> */
    public static function of(Deal $deal): Collection
    {
        return Original::where('deal_id', $deal->id)->orderBy('id')->get();
    }

    /** Три документа по умолчанию — один раз на сделку. @return Collection<int, Original> */
    public static function ensure(Deal $deal): Collection
    {
        return DB::transaction(function () use ($deal) {
            Deal::whereKey($deal->id)->lockForUpdate()->first();
            if (Original::where('deal_id', $deal->id)->exists()) {
                return Original::where('deal_id', $deal->id)->orderBy('id')->get();
            }
            foreach (self::DEFAULTS as [$kind, $title, $to]) {
                Original::create(['deal_id' => $deal->id, 'kind' => $kind, 'title' => $title, 'to' => $to]);
            }

            return Original::where('deal_id', $deal->id)->orderBy('id')->get();
        });
    }

    /** Наше дело на шаге (`DealTabs`): что-то ждёт отправки от нас — «Отправить оригиналы»; иначе ждём (null). */
    public static function todo(Deal $deal, ?\Carbon\Carbon $since = null): ?array
    {
        $items = self::of($deal);
        // Документов ещё нет (сделка встала на шаг до 26.11.2026) — их завести: тоже наше дело.
        if ($items->isNotEmpty() && $items->filter->ours()->isEmpty()) {
            return null;
        }

        return ['key' => 'originals', 'title' => 'Отправить оригиналы', 'rank' => 2, 'since' => $since,
            'button' => ['label' => 'Открыть', 'href' => '/offers/'.$deal->offer?->number]];
    }

    /** Где документ сейчас и трек СДЭК (шторка строки). Всё на месте — шаг проходит сам. */
    public static function update(Original $item, string $state, ?string $track, User $by): Original
    {
        // Под замком сделки (26.11.2026, ревью): двое отмечают последние документы разом — шаг проходит один раз.
        return DB::transaction(function () use ($item, $state, $track, $by) {
            Deal::whereKey($item->deal_id)->lockForUpdate()->first();

            return self::write($item->fresh(), $state, $track, $by);
        });
    }

    private static function write(Original $item, string $state, ?string $track, User $by): Original
    {
        if (! isset($item->choices()[$state])) {
            throw ValidationException::withMessages(['state' => 'Выберите, где документ']);
        }
        $track = $track !== null ? strtoupper(preg_replace('/\s+/u', '', $track)) : null;
        if ($state === 'sent' && ! preg_match('/^[A-Z0-9-]{6,40}$/', (string) $track)) {
            throw ValidationException::withMessages(['track' => 'Трек-номер СДЭК']);
        }
        $was = $item->place();
        $settled = (clone $item)->fill(['state' => $state])->settled();
        $item->update([
            'state' => $state,
            'track' => $track ?: $item->track,
            'sent_at' => $state === 'sent' ? ($item->sent_at ?? now()) : $item->sent_at,
            'done_at' => $settled ? ($item->done_at ?? now()) : null,
            'updated_by' => $by->id,
        ]);
        $deal = $item->deal;
        $deal->offer?->log(OfferEventType::Note, $by, ['text' => $item->title.': '.$was.' → '.$item->place().($item->track && $state === 'sent' ? ', трек '.$item->track : '')]);
        self::advance($deal, $by);

        return $item;
    }

    /** «+ Документ»: доп. соглашение и прочее, что тоже должно дойти. */
    public static function add(Deal $deal, string $title, string $to, User $by): Original
    {
        abort_unless(isset(self::DESTINATIONS[$to]), 422);
        self::ensure($deal);
        $item = Original::create(['deal_id' => $deal->id, 'kind' => 'extra', 'title' => $title, 'to' => $to, 'updated_by' => $by->id]);
        $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Оригиналы: добавлен «'.$title.'»']);

        return $item;
    }

    /** Убрать добавленный руками (три по умолчанию — только «Не нужен»). */
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
    public static function advance(Deal $deal, User $by): bool
    {
        $offer = $deal->offer?->fresh();
        $stage = $offer?->position(Track::Sale)?->stage?->loadMissing('exits');
        if (! $deal->isActive() || ! self::applies($stage)) {
            return false;
        }
        $items = Original::where('deal_id', $deal->id)->get();
        if ($items->isEmpty() || $items->contains(fn (Original $o) => ! $o->settled())) {
            return false;
        }
        $exit = $stage->exits->firstWhere('label', self::DONE) ?? $stage->exits->first();
        if (! $exit) {
            return false;
        }
        app(TakeExit::class)($offer, $exit, Actor::Staff, $by);

        return true;
    }

    /** Попросить менеджера прислать оригинал подписанного ДКП — сообщением в чат сделки. */
    public static function nudge(Deal $deal, User $by): void
    {
        abort_unless($deal->buyer_id && $deal->isActive(), 404);
        $chat = app(\App\Chats\Actions\OpenChat::class)($deal->offer, $deal->buyer, greet: false);
        app(\App\Chats\Actions\PlatformMessage::class)($chat, 'Нужен оригинал подписанного договора купли-продажи: '.$deal->offer->title()
            .'. Отправьте его нам СДЭКом и напишите трек-номер в этот чат');
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Попросили менеджера прислать оригинал ДКП']);
    }
}
