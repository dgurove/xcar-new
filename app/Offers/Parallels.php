<?php

namespace App\Offers;

use App\Billing\PaymentSource;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Live\RefreshDeal;
use App\Notifications\Notify;
use App\Notifications\ParallelBuyerNotice;
use App\Notifications\YourTurnNotice;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Параллельные сделки (07.11.2026, начальник: «одну сделку ведут сразу несколько менеджеров, и никто не знает о другом»;
 * Прадо 781R/046/01529/26 — Тужиков и Зайцев). Настоящая сделка у машины одна — главная: маршрут, письма поставщику,
 * счета, вывоз — её. Параллельная (`DealState::Parallel`) — сделка менеджера со своим покупателем и своим **теневым шагом**
 * (`deals.shadow_stage_id`): шаги поставщика до реквизитов он видит те же, что главная (там правда ждём поставщика), шаги
 * менеджера — свои («Согласие менеджера», «Нет реквизитов» — свои просьбы, сроки, «Ваш ход»), а дальше письма с
 * реквизитами не идёт никогда: у него «реквизиты получены», как будто письмо ещё не ушло. Переключиться на его реквизиты —
 * `Actions\TakeParallel`, закрыть — `Actions\CloseParallels`, поручить — «Отдать… → Поручить параллельно» (`Give`).
 */
final class Parallels
{
    /** Живые параллельные сделки машины, по порядку поручения. */
    public static function of(Offer|int $offer): Collection
    {
        return Deal::where('offer_id', $offer instanceof Offer ? $offer->id : $offer)->where('state', DealState::Parallel)
            ->with(['buyer', 'bid', 'shadowStage.block', 'contract.buyer.party'])->orderBy('id')->get();
    }

    /** Порядок шага в маршруте: блок, затем шаг в блоке. Ветки сравниваются так же — гаражная стоит после покупательской. */
    public static function rank(Stage $stage): int
    {
        $stage->loadMissing('block');

        return (int) ($stage->block?->position ?? 0) * 1000 + (int) $stage->position;
    }

    /** Шаг «Нет реквизитов» маршрута (`Asks::Buyer`); у маршрута без него — null. */
    public static function buyerStage(Stage $any): ?Stage
    {
        return self::buyerStageOf((int) $any->workflow_id);
    }

    /** Кэш на запрос — по номеру маршрута, а не по экземпляру этапа (у `once` ключ — аргументы вызова). */
    private static function buyerStageOf(int $workflowId): ?Stage
    {
        return once(fn () => Stage::where('workflow_id', $workflowId)->where('asks', Asks::Buyer->value)->with(['block', 'exits.to.block', 'template'])->orderBy('id')->first());
    }

    /** Куда ведёт «Нет реквизитов» исходом менеджера — «Письмо с реквизитами»: дальше параллельная не идёт. */
    public static function requisitesStage(Stage $buyer, Deal $deal): ?Stage
    {
        return $buyer->exitsFor(Actor::Manager, $deal)->first()?->to?->loadMissing(['block', 'exits.to', 'template']);
    }

    /** Данные покупателя готовы — как для шага «Нет реквизитов» у главной (`AdvanceOnBuyerData`). */
    public static function ready(Deal $deal): bool
    {
        return (bool) $deal->loadMissing('contract.buyer.party')->contract?->buyer?->party?->readyAsBuyer();
    }

    /** Шаг, куда параллельная не заходит: оплата, наша оплата, получение, конец сделки — их видит только главная. */
    private static function beyond(Stage $stage): bool
    {
        return $stage->isPayStep() || $stage->paysOut() || $stage->isPickupStep() || $stage->waits_for === WaitsFor::Nobody
            || ! in_array($stage->offer_state, [null, OfferState::Sold], true);
    }

    /**
     * Где встать параллельной, когда главная на `$main`, а она сама на `$from`: своё дело (открытая просьба) держит её на
     * месте; до «Нет реквизитов» она идёт за главной, но не назад; главная на реквизитах или дальше — у неё «Нет реквизитов»,
     * пока её данные не готовы, потом «Письмо с реквизитами» навсегда. Маршрут без шага реквизитов — за главной до оплаты.
     */
    public static function target(Deal $deal, ?Stage $from, Stage $main): ?Stage
    {
        if ($from && self::pending($deal, $from)) {
            return $from;
        }
        $buyer = self::buyerStage($main);
        if ($buyer && self::rank($main) >= self::rank($buyer)) {
            return self::ready($deal) ? (self::requisitesStage($buyer, $deal) ?? $buyer) : $buyer;
        }
        $to = $from && self::rank($from) >= self::rank($main) ? $from : $main;

        return self::beyond($to) ? $from : $to;
    }

    /** У параллельной открыта своя просьба на этом шаге — её ход, шаг не сдвигается. */
    public static function pending(Deal $deal, Stage $stage): bool
    {
        return Requirement::where('deal_id', $deal->id)->where('stage_id', $stage->id)->whereNull('done_at')->exists();
    }

    /**
     * Теневой шаг как позиция маршрута — для страницы, строк и «Ваш ход» менеджера (в базу не пишется): срок — его
     * просьбы; на «Письме с реквизитами» ход наш и письма нет, как до отправки.
     */
    public static function position(Deal $deal, ?Offer $offer = null): ?Position
    {
        $stage = $deal->shadowStage;
        if (! $stage) {
            return null;
        }
        $stage->loadMissing(['block', 'workflow', 'exits.to', 'template']);
        $req = $deal->relationLoaded('openRequirement') ? $deal->openRequirement : $deal->openRequirement()->first();
        // Чей ход: на «Письме с реквизитами» — наш, как до письма (его реквизиты никуда не уходят); на общем шаге с главной —
        // как его видит менеджер главной (`waitsForManager`: письмо о покупке ушло — ждём поставщика); иначе — как у этапа.
        $main = $deal->offer?->position(Track::Sale);
        $waits = match (true) {
            $stage->isRequisitesStep() => WaitsFor::Us,
            $main && $main->stage_id === $stage->id && ! $req => $main->waitsForManager(),
            default => null,
        };
        $position = new Position(['offer_id' => $deal->offer_id, 'track' => Track::Sale->value, 'stage_id' => $stage->id,
            'waits_for' => $waits, 'entered_at' => $deal->shadow_at, 'block_entered_at' => $deal->shadow_at,
            'deadline_at' => $req?->due_at, 'payload' => null]);
        $position->setRelation('stage', $stage);
        if ($offer) {
            $position->setRelation('offer', $offer);
        }

        return $position;
    }

    /**
     * Параллельная сделка, одетая для вида менеджера: своя копия машины, у которой шаг продажи — теневой. Всё, что
     * спрашивает `$deal->offer->position()`, видит его шаг, а не главной. Идущую не трогает.
     */
    public static function dress(Deal $deal): Deal
    {
        // Закрытая параллельная (и бывшая главная, ставшая параллельной) — тоже: шага машины ей не показываем никогда.
        if (! $deal->offer || ! ($deal->isParallel() || (! $deal->isActive() && $deal->wasParallel()))) {
            return $deal;
        }
        $offer = clone $deal->offer;
        $position = self::position($deal, $offer);
        $offer->setRelation('positions', $position ? new Collection([$position]) : new Collection);

        return $deal->setRelation('offer', $offer);
    }

    /**
     * Исходы менеджера на теневом шаге: на согласии — только «Беру в работу» и «Я передумал» (в гараж параллельную не
     * забирают), «Нет реквизитов» проходит сам по данным покупателя.
     */
    public static function exits(Deal $deal, Stage $stage): \Illuminate\Support\Collection
    {
        $exits = $stage->exitsFor(Actor::Manager, $deal);

        return $stage->asksConsent() ? $exits->filter(fn (Outcome $e) => $e->takes() || $e->isRetreat())->values() : $exits;
    }

    /**
     * Встать параллельной на шаг: прежняя её просьба закрывается, на шаге менеджера — новая (срок, «Ваш ход», Telegram и
     * чат на согласии — как у главной), прошла согласование — `agreed_at` (у неё это только вид: счетов нет). Шаг
     * «Нет реквизитов» с готовыми данными — сразу дальше. `$quiet` — без уведомления: просьба та же, что он уже получил.
     */
    public static function enter(Deal $deal, Stage $to, ?User $by = null, ?string $label = null, bool $quiet = false, bool $log = true): void
    {
        $to->loadMissing(['block', 'workflow', 'exits.to', 'template']);
        $from = $deal->shadowStage;
        $now = Carbon::now();
        if ($from?->id === $to->id) {
            return;
        }
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => $now, 'answer' => json_encode(['closed_by' => 'stage'])]);
        $deal->forceFill(['shadow_stage_id' => $to->id, 'shadow_at' => $now]
            + (! $deal->agreed_at && ! $to->isAgreement() && in_array($to->offer_state, [null, OfferState::Sold], true) ? ['agreed_at' => $now] : []))->save();
        $deal->setRelation('shadowStage', $to)->unsetRelation('openRequirement');
        // Первый шаг пишет «Поручено параллельно» одной строкой (`Give`, `$log = false`): путь менеджера читает и её.
        $log && $deal->offer->log(OfferEventType::Parallel, $by, ['do' => 'stage', 'deal' => $deal->id, 'manager' => $deal->buyer_id, 'track' => Track::Sale->value,
            'from' => $from?->name, 'to' => $to->name, 'from_id' => $from?->id, 'to_id' => $to->id, 'block' => $to->block?->name, ...($label ? ['label' => $label] : [])]);

        $buyerDone = $to->asks === Asks::Buyer && self::ready($deal);
        if (! $buyerDone && self::exits($deal, $to)->isNotEmpty()) {
            $requirement = Requirement::askFor($deal, $to, self::position($deal) ?? new Position(['deadline_at' => null]));
            $requirement->update(['due_at' => $to->deadlineFor($deal->offer, $now)]);
            if (! $quiet && $deal->buyer) {
                $deal->buyer->notify(new YourTurnNotice($requirement->load(['offer', 'deal', 'stage'])));
                if ($to->asksConsent()) {
                    [$offer, $manager] = [$deal->offer, $deal->buyer];
                    DB::afterCommit(fn () => app(PlatformMessage::class)(
                        app(OpenChat::class)($offer, $manager, greet: false), Notify::CONSENT_ASK));
                }
            }
        }
        // Реквизиты пришли — нам громко: может, переключимся на него (владелец).
        if (! $quiet && $from?->asks === Asks::Buyer && $to->isLetterStep() && self::ready($deal)) {
            Notification::send(User::withRole(Role::Admin)->get(), new ParallelBuyerNotice($deal->loadMissing(['offer', 'buyer', 'contract.buyer.party'])));
        }
        RefreshDeal::of($deal);
        if ($buyerDone && ($next = self::requisitesStage($to, $deal))) {
            self::enter($deal, $next, $by, 'Данные покупателя заполнены', $quiet);
        }
    }

    /** Все параллельные машины — за главной (`FollowParallels` на каждый её шаг и после «Взять в работу»). */
    public static function follow(Offer $offer, ?User $by = null, bool $quiet = false): void
    {
        $main = $offer->unsetRelation('positions')->position(Track::Sale)?->stage;
        if (! $main || ! $offer->deal()->exists()) {
            return;
        }
        foreach (self::of($offer) as $deal) {
            $deal->setRelation('offer', $offer);
            $to = self::target($deal, $deal->shadowStage, $main);
            if ($to && $to->id !== $deal->shadow_stage_id) {
                self::enter($deal, $to, $by, quiet: $quiet);
            }
        }
    }

    /**
     * Письмо с реквизитами главной уже ушло (или шаг дальше — счёт пришёл): переключиться — значит перевыставить счёт.
     * Одна дверь для действия (`TakeParallel`) и вопроса в CRM (`takeAsk`).
     *
     * @return array{stage: ?Stage, buyer: ?Stage, letter: ?Stage, sent: bool}
     */
    public static function requisitesState(Offer $offer, Deal $deal): array
    {
        $position = $offer->position(Track::Sale);
        $stage = $position?->stage?->loadMissing(['block', 'exits.to', 'template']);
        $buyer = $stage ? self::buyerStage($stage) : null;
        $letter = $buyer ? self::requisitesStage($buyer, $deal) : null;
        $sent = $stage && $letter && (self::rank($stage) > self::rank($letter)
            || ($stage->id === $letter->id && in_array($position->letterStatus(), ['sent', 'replied', 'bounced'], true)));

        return ['stage' => $stage, 'buyer' => $buyer, 'letter' => $letter, 'sent' => $sent];
    }

    /**
     * Что будет по «Взять в работу» — вопросом подтверждения в CRM; `locked` — почему нельзя (кнопки тогда нет). Почему
     * нельзя — общее для всех параллельных машины: вид считает его один раз (`$locked`).
     *
     * @return array{ask: string, locked: ?string}
     */
    public static function takeAsk(Deal $deal, ?Deal $main, ?string $locked = null): array
    {
        $name = $deal->buyer?->shortName() ?? 'менеджер';
        if (! $main) {
            return ['ask' => 'Взять в работу '.$name.'? ТС уйдёт ему в сделку', 'locked' => null];
        }
        if ($locked) {
            return ['ask' => '', 'locked' => $locked];
        }
        ['letter' => $letter, 'sent' => $sent] = self::requisitesState($main->offer, $deal);
        $was = $main->buyer?->shortName() ?? 'прежний';

        return ['ask' => $sent
            ? 'Реквизиты '.$was.' поставщику уже ушли. Взять в работу '.$name.'? Шаг вернётся на «'.$letter->name.'» — письмо «перевыставить счёт»'
            : 'Взять в работу '.$name.'? Шаг остаётся, '.$was.' — параллельно и ничего не узнает', 'locked' => null];
    }

    /**
     * Почему главную нельзя переключить и параллельно уже не поручить: по ней платили (поставщику, за ТС, по счёту) или она
     * дальше оплаты; менеджер думает про новую закупочную. null — можно.
     */
    public static function locked(Deal $main): ?string
    {
        $main->loadMissing('offer');
        $stage = $main->offer?->position(Track::Sale)?->stage;
        $pay = $stage ? Stage::where('workflow_id', $stage->workflow_id)->with(['block', 'exits'])->get()->first(fn (Stage $s) => $s->isPayStep()) : null;
        $paid = $main->prepaid_at || $main->supplier_paid_at
            || $main->issuedInvoices()->whereHas('payments', fn ($p) => $p->whereNotIn('source', PaymentSource::soft()))->exists()
            || ($stage && $pay && self::rank($stage) > self::rank($pay));

        return match (true) {
            $main->isGarage() => 'Гаражную сделку не переключить',
            $main->isAuction() => 'Продажу на аукционе не переключить',
            $paid => 'По сделке уже платили: переключить нельзя',
            RepriceAsk::where('deal_id', $main->id)->whereIn('state', RepriceAsk::OPEN)->exists() => 'Не решили с менеджером новую цену: сначала это',
            default => null,
        };
    }
}
