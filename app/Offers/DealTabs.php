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
 * поставщик ответил на согласовании, деньги от менеджера пришли раньше счёта, дальше блок шага продажи (`BLOCKS`), не из
 * таблицы — «Согласование» (до 17.11.2026 «Ждём согласия поставщика»).
 * Наш ли ход — есть ли дело (`Position::todo`): зелёное число у названия вкладки и зелёные группы внутри.
 * Раскладка всех сделок — раз на 30 с (`Cache`, сброс — `Nav::forgetStaffCounts`): колесо считает её на каждой странице.
 */
final class DealTabs
{
    /**
     * Ключ → подпись, в порядке маршрута. Подпись — кто что делает (16.11.2026, владелец выбрал из двух вариантов: «Реквизиты и
     * счёт» и прочие существительные — «непонятно»). Ключи — адреса вкладок, их не меняем.
     */
    public const TABS = [
        'reprice' => 'Переоценка',
        'letter' => 'Написать поставщику',
        'answered' => 'Поставщик ответил',
        // «Согласование» (17.11.2026, владелец: под «Ждём согласия поставщика» стояли «ждём менеджера» — непонятно): сюда идёт
        // всё, что не из таблицы блоков, — согласие менеджера и согласие поставщика.
        'agree' => 'Согласование',
        'invoice' => 'Ждём счёт от поставщика',
        'paying' => 'Менеджер должен нам',
        'payout' => 'Мы должны поставщику',
        'pickup' => 'Менеджер забирает ТС',
        'docs' => 'Подписание договора',
    ];

    /** Заголовок группы дела, когда дело — само название этапа (`Position::todo` по умолчанию): действием, а не этапом. */
    public const TODO_TITLES = [
        'Проверка оплаты' => 'Проверить оплату',
        'Проверка оплаты подбора' => 'Проверить оплату подбора',
        'Оплата поставщику' => 'Оплатить поставщику',
        'Оплата поставщику, в гараж' => 'Оплатить поставщику, ТС в наш гараж',
        'Перевод поставщику' => 'Перевести деньги поставщику',
    ];

    /** Блок шага продажи → вкладка; блок не из таблицы — согласование. Заявка Каркаде — то же письмо, что ещё не ушло. */
    public const BLOCKS = [
        'Реквизиты для счёта' => 'invoice',
        'Собираем заявку поставщику' => 'letter',
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
            // Деньги от менеджера пришли раньше счёта — дооформить его оплату. «Себе» без менеджера (деньги покупателя гаражной) —
            // во вкладке своего блока.
            str_starts_with($key, 'gap:paid') && $position?->deal?->buyer_id => 'paying',
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
        'buyer', 'openRequirement', 'invoices.claims', 'invoices.party', 'contract', 'garageCar'];

    /** Группы сделок без нашего дела, по порядку. */
    public const WAITS = ['manager' => 'Ждём менеджера', 'supplier' => 'Ждём поставщика', 'transport' => 'Везёт наша логистика', 'other' => 'Прочее'];

    /** Кого ждём по сделке без нашего дела (`WAITS`): ход наш, но ТС везёт логистика (`Position::carried`), — своя группа. */
    public static function waits(Deal $deal): string
    {
        $position = $deal->offer?->position();

        return match (true) {
            $position?->carried() === true => 'transport',
            $position?->waitsFor() === WaitsFor::Manager => 'manager',
            $position?->waitsFor() === WaitsFor::Supplier => 'supplier',
            default => 'other',
        };
    }
}
