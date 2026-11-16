<?php

namespace App\Offers;

use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Вкладки сделки в «Предложениях» — по этапам маршрута (16.11.2026, владелец: «„Ваш ход“ ничего не говорит об этапе, разбить на
 * конкретные вкладки», «пришли согласования от поставщика — отдельная вкладка, переоценка — отдельная»; «Расчёт» — непонятно,
 * кому не заплачено). Каждая идущая сделка — ровно в одной вкладке, правила по порядку: переоценка, письмо поставщику не ушло,
 * поставщик ответил на согласовании, дальше блок шага продажи (`BLOCKS`), не из таблицы — «Ждём согласия поставщика».
 * Наш ли ход — есть ли дело (`Position::todo`): зелёное число у названия вкладки и зелёные группы внутри.
 * Раскладка всех сделок — раз на 30 с (`Cache`, сброс — `Nav::forgetStaffCounts`): колесо считает её на каждой странице.
 */
final class DealTabs
{
    /** Ключ → подпись, в порядке маршрута. */
    public const TABS = [
        'reprice' => 'Переоценка',
        'letter' => 'Отправить письмо поставщику',
        'answered' => 'Поставщик ответил',
        'agree' => 'Ждём согласия поставщика',
        'invoice' => 'Реквизиты и счёт',
        'paying' => 'Оплата от менеджера',
        'payout' => 'Оплата поставщику',
        'pickup' => 'Получение ТС',
        'docs' => 'Договор',
    ];

    /** Блок шага продажи → вкладка; блок не из таблицы — согласование. */
    public const BLOCKS = [
        'Реквизиты для счёта' => 'invoice',
        'Собираем заявку поставщику' => 'invoice',
        'Оплата' => 'paying',
        'Расчёт с поставщиком' => 'payout',
        'Взяли под себя' => 'payout',
        'Оформляем на нас' => 'payout',
        'Получение автомобиля' => 'pickup',
        'Договор' => 'docs',
        'Оформление документов' => 'docs',
    ];

    private const CACHE = 'deal-tabs';

    /** Вкладка и наш ли ход одной сделки по её шагу и делу. */
    public static function of(?Position $position, ?array $todo): string
    {
        $key = $todo['key'] ?? '';
        $block = self::BLOCKS[$position?->stage?->block?->name ?? $position?->stage?->name ?? ''] ?? 'agree';

        return match (true) {
            in_array($key, ['reprice', 'reprice-answer', 'price-ask'], true) => 'reprice',
            in_array($key, ['letter', 'bounced', 'requisites'], true) || str_starts_with($key, 'request:') => 'letter',
            $key === 'reply' && $block === 'agree' => 'answered',
            default => $block,
        };
    }

    /**
     * Дело шага у идущих сделок — одной выборкой (переоценки письмом — одним запросом, как было в «Ваш ход»).
     *
     * @param  Collection<int, Deal>  $deals  со связями `offer.positions.stage…`
     * @return Collection<int, array{deal: Deal, tab: string, todo: ?array}> по `deal.id`
     */
    public static function rows(Collection $deals): Collection
    {
        $letters = Reprice::whereIn('offer_id', $deals->pluck('offer_id')->filter()->unique())->where('state', Reprice::APPLIED)
            ->where('source', 'letter')->whereNull('answered_at')->distinct()->pluck('offer_id')->flip();

        return $deals->mapWithKeys(function (Deal $deal) use ($letters) {
            $position = $deal->offer?->position();
            $position?->setRelation('offer', $deal->offer)->setRelation('deal', $deal);
            $position && ($position->letterRepriceKnown = $letters->has($deal->offer_id));
            $todo = $position?->todo();

            return [$deal->id => ['deal' => $deal, 'tab' => self::of($position, $todo), 'todo' => $todo]];
        });
    }

    /** Раскладка всех идущих сделок: `offer_id` → [вкладка, наш ход]. @return array<int, array{0: string, 1: bool}> */
    public static function map(): array
    {
        return Cache::remember(self::CACHE, 30, fn () => self::rows(Deal::active()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Sold))
            ->with(self::WITH)->get())->mapWithKeys(fn (array $r) => [$r['deal']->offer_id => [$r['tab'], $r['todo'] !== null]])->all());
    }

    /** Машины вкладки (`OfferController::scopeFor`). @return list<int> */
    public static function ids(string $tab): array
    {
        return array_keys(array_filter(self::map(), fn (array $v) => $v[0] === $tab));
    }

    /** Сколько ждёт нас по вкладкам. @return array<string, int> */
    public static function ours(): array
    {
        return collect(self::map())->filter(fn (array $v) => $v[1])->countBy(fn (array $v) => $v[0])->all();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** Связи, что нужны делу шага (`Position::todo`) и строкам. */
    public const WITH = ['offer', 'offer.vendor', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice',
        'buyer', 'openRequirement', 'invoices.claims', 'invoices.party', 'contract'];

    /** Кого ждём по сделке без нашего дела — группа «Ждём менеджера» / «Ждём поставщика». */
    public static function waits(Deal $deal): string
    {
        return match ($deal->offer?->position()?->waitsFor()) {
            WaitsFor::Manager => 'manager',
            WaitsFor::Supplier => 'supplier',
            default => 'other',
        };
    }
}
