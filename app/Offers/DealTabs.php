<?php

namespace App\Offers;

use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Support\Collection;
use App\Support\StaffCache;
use Illuminate\Support\Facades\Cache;

/**
 * Вкладки сделки в «Предложениях» — по этапам маршрута (16.11.2026, владелец: «„Ваш ход“ ничего не говорит об этапе, разбить на
 * конкретные вкладки», «пришли согласования от поставщика — отдельная вкладка, переоценка — отдельная»; «Расчёт» — непонятно,
 * кому не заплачено). Каждая идущая сделка — ровно в одной вкладке. С 17.11.2026 вкладки двух видов (владелец: «непонятно, почему
 * счётчик у „Менеджер должен нам“ горит, если он нам должен, — мы же ничего сделать не можем»): «Сделать» — есть наше дело
 * (`Position::todo`), вкладка по виду дела, красный счётчик; «Ждём» (`WAITING`) — дела нет, вкладка по блоку шага (`BLOCKS`),
 * в колесе полупрозрачная и без счётчика. Правило — `of`.
 * Раскладка всех сделок — раз на 30 с (`Cache`, сброс — `Nav::forgetStaffCounts`): колесо считает её на каждой странице.
 */
final class DealTabs
{
    /**
     * Ключ → подпись, в порядке маршрута. Подпись — кто что делает (16.11.2026, владелец выбрал из двух вариантов: «Реквизиты и
     * счёт» и прочие существительные — «непонятно»). Ключи — адреса вкладок, их не меняем.
     */
    public const TABS = [
        // «Сделать» — наш ход (17.11.2026, владелец: «разлепить, где точно от нас требуется действие, а где мы просто ждём»; «в
        // „Менеджер должен нам“ — только то, что ожидает платежа; пришли оплаты и их надо проверить — „Проверить оплату“; нужно
        // счёт отправить — тоже отдельная вкладка»): строка здесь только при нашем деле, вкладка — по виду дела (`of`).
        'reprice' => 'Переоценка',
        'letter' => 'Написать поставщику',
        // Вопрос менеджеру (18.11.2026): письмо Т-Страхования пришло — спросить, берёт ли в работу (`Position::todo` `ask-consent`).
        'ask' => 'Спросить менеджера',
        'answered' => 'Поставщик ответил',
        'check' => 'Проверить оплату',
        'bill' => 'Отправить счёт',
        'arrived' => 'Деньги пришли',
        'payout' => 'Оплатить поставщику',
        'todo' => 'Другие дела',
        // «Ждём» — нашего дела нет (`WAITING`): обычным шрифтом, полупрозрачные, без красного счётчика (владелец: «такие вкладки
        // делал бы regular font полупрозрачный, чтобы показать, что там чисто ждать надо»). Вкладка — по блоку шага (`BLOCKS`).
        // «На согласовании» (17.11.2026, владелец: «не Согласование, а На согласовании») — согласие менеджера и поставщика.
        'agree' => 'На согласовании',
        'invoice' => 'Ждём поставщика',
        'paying' => 'Менеджер должен нам',
        'pickup' => 'Менеджер забирает ТС',
        'docs' => 'Подписание договора',
    ];

    /** Вкладки «Ждём»: нашего дела в них нет. */
    public const WAITING = ['agree', 'invoice', 'paying', 'pickup', 'docs'];

    /** Группы «Ждём поставщика» по шагу: чего ждём. */
    public const WAIT_STAGES = ['Письмо с реквизитами' => 'Ждём счёт', 'Подтверждение оплаты' => 'Ждём подтверждение оплаты'];

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

    /**
     * Вкладка и наш ли ход одной сделки по её шагу и делу. `$repricing` — вопрос переоценки ещё не закрыт (`RepriceFlow::for`,
     * 17.11.2026, владелец: «пока вопрос переоценки не закрыт, она должна быть в переоценке всё время, в нужной подгруппе, никуда не
     * уходить, пока не решится вопрос с новой ценой»): и когда ход менеджера, и когда он назвал новую цену.
     */
    public static function of(?Position $position, ?array $todo, bool $repricing = false): string
    {
        $key = $todo['key'] ?? '';
        $block = self::BLOCKS[$position?->stage?->block?->name ?? $position?->stage?->name ?? ''] ?? 'agree';
        if ($todo === null && ! $repricing) {
            // Ждём. Блок, что сам вкладка «Сделать» (оплата поставщику, заявка), без нашего дела — к тому, кого ждём; ждём
            // поставщика на шагах оплаты (подтверждение оплаты, счёт) — «Ждём поставщика», а не «Менеджер должен нам».
            $supplier = $position?->waitsFor() === WaitsFor::Supplier;

            return match (true) {
                in_array($block, self::WAITING, true) && ! ($supplier && $block === 'paying') => $block,
                $supplier => 'invoice',
                $block === 'payout' => 'paying',
                default => 'agree',
            };
        }

        return match (true) {
            $repricing || in_array($key, ['reprice', 'reprice-answer', 'price-ask', 'price-offer'], true) => 'reprice',
            in_array($key, ['bounced', 'requisites'], true) || str_starts_with($key, 'letter') || str_starts_with($key, 'request:') => 'letter',
            $key === 'ask-consent' => 'ask',
            // Ответ поставщика на разбор — на каком бы шаге ни пришёл.
            $key === 'reply' => 'answered',
            str_starts_with($key, 'stage:Проверка оплаты') => 'check',
            // Деньги пришли раньше счёта — дооформить (записать продажу, покупатель для договора).
            str_starts_with($key, 'gap:paid') => 'arrived',
            // Счёта нет или менеджер его ещё не получил: выставить, отправить, назначить вознаграждение.
            str_starts_with($key, 'gap:') => 'bill',
            // Шаг нашей оплаты поставщику, в каком бы блоке он ни стоял.
            (bool) $position?->stage?->paysOut() => 'payout',
            default => 'todo',
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
        $offers = $deals->pluck('offer_id')->filter()->unique();
        $letters = Reprice::whereIn('offer_id', $offers)->where('state', Reprice::APPLIED)
            ->where('source', 'letter')->whereNull('answered_at')->distinct()->pluck('offer_id')->flip();
        // У кого вообще есть переоценка (ждущая или разобранная) — только им спрашиваем, закрыт ли вопрос (`RepriceFlow::for`).
        $repriced = Reprice::whereIn('offer_id', $offers)->whereIn('state', [Reprice::PENDING, Reprice::APPLIED])->distinct()->pluck('offer_id')->flip();
        // Вопрос открыт и без `RepriceFlow` (17.11.2026, владелец про Audi RS 6 Сергея — гаражная сделка, у неё шага «Переоценка» нет):
        // ждущая переоценка или менеджер ещё не ответил про новую цену.
        $pending = Reprice::whereIn('offer_id', $offers)->where('state', Reprice::PENDING)->distinct()->pluck('offer_id')->flip();
        $asked = RepriceAsk::whereIn('deal_id', $deals->pluck('id'))->whereIn('state', RepriceAsk::OPEN)->distinct()->pluck('deal_id')->flip();

        return $deals->mapWithKeys(function (Deal $deal) use ($letters, $repriced, $pending, $asked) {
            $position = $deal->offer?->position();
            $position?->setRelation('offer', $deal->offer)->setRelation('deal', $deal);
            $position && ($position->letterRepriceKnown = $letters->has($deal->offer_id));
            $todo = $position?->todo();
            $repricing = $pending->has($deal->offer_id) || $asked->has($deal->id)
                || ($repriced->has($deal->offer_id) && $deal->offer && RepriceFlow::for($deal->offer->setRelation('deal', $deal)) !== null);
            // Менеджер думает про новую цену — подгруппа «Ждём менеджера» (`waits`), а не дело шага («Прочитать ответ поставщика» — это
            // и есть письмо переоценки, его черёд после ответа). Свои дела переоценки (не ответил к сроку, назвал цену) — остаются.
            if ($asked->has($deal->id) && ! in_array($todo['key'] ?? '', ['reprice', 'reprice-answer', 'price-ask', 'price-offer'], true)) {
                $todo = null;
            }

            return [$deal->id => ['deal' => $deal, 'tab' => self::of($position, $todo, $repricing), 'todo' => $todo]];
        });
    }

    /** Раскладка всех идущих сделок: `offer_id` → [вкладка, наш ход]. @return array<int, array{0: string, 1: bool}> */
    public static function map(): array
    {
        // Непроданная гаражная «платит менеджер» — только в «Гараже» (17.11.2026, владелец), во вкладках сделки её нет.
        // Раскладке нужны шаг и дело, а не строка: без кадров машин (`offer.media`) — с ними выборка 360 сделок шла
        // полсекунды вместо 0,1 с, и это была главная цена пересчёта после каждого события (17.11.2026).
        return StaffCache::remember(self::CACHE, 30, fn () => self::rows(Deal::active()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Sold))
            ->with(array_values(array_diff(self::WITH, ['offer.media'])))->get())->reject(fn (array $r) => $r['deal']->sellsFromGarage($r['deal']->offer?->position()?->stage))
            ->mapWithKeys(fn (array $r) => [$r['deal']->offer_id => [$r['tab'], $r['todo'] !== null]])->all());
    }

    /** Машины вкладки (`OfferController::scopeFor`). @return list<int> */
    public static function ids(string $tab): array
    {
        return array_keys(array_filter(self::map(), fn (array $v) => $v[0] === $tab));
    }

    /** Сколько ждёт нас по вкладкам. @return array<string, int> */
    public static function ours(): array
    {
        return collect(self::map())->filter(fn (array $v) => $v[1] && ! in_array($v[0], self::WAITING, true))->countBy(fn (array $v) => $v[0])->all();
    }

    public static function forget(): void
    {
        StaffCache::bump(StaffCache::TABS);
    }

    /** Связи, что нужны делу шага (`Position::todo`) и строкам. */
    public const WITH = ['offer', 'offer.vendor', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice',
        'buyer', 'openRequirement', 'invoices.claims', 'invoices.party', 'contract', 'garageCar'];

    /** Группы сделок без нашего дела, по порядку. */
    public const WAITS = ['manager' => 'Ждём менеджера', 'supplier' => 'Ждём поставщика', 'transport' => 'Везёт наша логистика', 'other' => 'Прочее'];

    /** Заголовок группы ожидания по ключу `waits`. */
    public static function waitTitle(string $key): string
    {
        return str_contains($key, ':') ? self::WAIT_STAGES[explode(':', $key, 2)[1]] : self::WAITS[$key];
    }

    /** Кого ждём по сделке без нашего дела (`WAITS`): ход наш, но ТС везёт логистика (`Position::carried`), — своя группа. */
    public static function waits(Deal $deal): string
    {
        $position = $deal->offer?->position();

        return match (true) {
            // Менеджер думает про новую цену (`RepriceAsk`) — ждём его, какой бы шаг ни стоял (гаражная без шага переоценки).
            RepriceAsk::where('deal_id', $deal->id)->whereIn('state', RepriceAsk::OPEN)->exists() => 'manager',
            $position?->carried() === true => 'transport',
            $position?->waitsFor() === WaitsFor::Manager => 'manager',
            // Чего ждём от поставщика на шагах оплаты — своей группой («Ждём счёт», «Ждём подтверждение оплаты»).
            $position?->waitsFor() === WaitsFor::Supplier => isset(self::WAIT_STAGES[$position->stage?->name]) ? 'supplier:'.$position->stage->name : 'supplier',
            default => 'other',
        };
    }
}
