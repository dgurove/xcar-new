<?php

namespace App\Offers\Actions;

use App\Chats\StepAsk;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Notifications\YourTurnNotice;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Parallels;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Взять в работу» параллельную сделку (07.11.2026, владелец: «будем решать, какие реквизиты берём в работу и отправляем в
 * страховую; может, переключимся на него — сори, перевыставьте вот на эти реквизиты»). Сделка этого менеджера становится
 * главной **той же строкой** (ссылки, покупатель, чат у него остаются), прежняя главная — параллельной: у её менеджера
 * теневой шаг, как будто письма не было. Шаг машины не сбрасывается:
 *
 * - до «Нет реквизитов» — стоит, просьба шага (согласие) — новому; своё согласие он уже дал — проходит сам;
 * - на «Нет реквизитов» и на «Письме с реквизитами», пока письмо не ушло, — данные нового (готовы — шаг проходит сам);
 * - письмо ушло или счёт уже пришёл — снова «Письмо с реквизитами» письмом «перевыставить счёт» (`Template::reissue`);
 * - платили — нельзя (`Parallels::locked`).
 *
 * Идущей сделки нет (главную отменили, ТС снова в приёме) — вход в сделку, как у «Отдать» (`Give::promote`). Счета прежней —
 * гаснут, новой — встают (`SyncDealInvoices`). Прежнему ничего не пишется.
 */
final class TakeParallel
{
    public function __construct(private EnterStage $enter, private TakeExit $takeExit, private SyncDealInvoices $invoices, private Give $give) {}

    /** @return array{deal: Deal, reissue: bool} */
    public function __invoke(Deal $deal, User $by): array
    {
        return DB::transaction(function () use ($deal, $by) {
            $offer = Offer::whereKey($deal->offer_id)->lockForUpdate()->firstOrFail();
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! $deal->isParallel()) {
                throw ValidationException::withMessages(['take' => 'Эта сделка уже не параллельная']);
            }
            $deal->setRelation('offer', $offer)->load(['buyer', 'shadowStage.block']);
            $main = $offer->deal()->lockForUpdate()->first();

            return $main ? $this->swap($offer, $main, $deal, $by) : ['deal' => $this->promote($offer, $deal, $by), 'reissue' => false];
        });
    }

    /** @return array{deal: Deal, reissue: bool} */
    private function swap(Offer $offer, Deal $main, Deal $deal, User $by): array
    {
        if ($why = Parallels::locked($main->setRelation('offer', $offer))) {
            throw ValidationException::withMessages(['take' => $why]);
        }
        ['stage' => $stage, 'buyer' => $buyer, 'letter' => $letter, 'sent' => $sent] = Parallels::requisitesState($offer, $deal);
        $shadow = $deal->shadowStage;
        // Прежний главный не дал согласия, где его спрашивают, — у него это и останется его ходом.
        $mainConsent = $stage?->asksConsent() && Requirement::where('deal_id', $main->id)->where('stage_id', $stage->id)->whereNull('done_at')->exists();

        // Прежняя главная — параллельная, сразу со своим теневым шагом: пока маршрут ниже сдвигается, она уже тень.
        $main->forceFill(['state' => DealState::Parallel, 'shadow_stage_id' => null, 'shadow_at' => null, 'reissue_at' => null])->save();
        Requirement::where('deal_id', $main->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'parallel'])]);
        CancelDeal::voidUnpaid($main, $by, 'Сделку взял другой менеджер');
        // Забирать должен был он сам и ещё не забрал — поручение снимается, кто вывозит, решает админ (как при отмене).
        $open = Transport::openFor($offer);
        if ($open && ! $open->taken() && $open->channel === Channel::Manager && $open->executor_id === $main->buyer_id) {
            app(AssignExecutor::class)->release($open, $by);
        }
        $main->setRelation('offer', $offer);
        if ($stage) {
            $from = $letter && Parallels::rank($stage) > Parallels::rank($letter) ? $letter : $stage;
            Parallels::enter($main, $mainConsent ? $stage : (Parallels::target($main, $from, $stage) ?? $from), $by, 'Взяли в работу другого', quiet: true);
        }

        // Параллельная — главная, на том же шаге; согласование — как было у машины.
        $deal->forceFill(['state' => DealState::Active, 'shadow_stage_id' => null, 'shadow_at' => null, 'agreed_at' => $main->agreed_at,
            'reissue_at' => $sent ? now() : null])->save();
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'parallel'])]);
        $offer->unsetRelation('deal')->unsetRelation('positions');
        $offer->log(OfferEventType::Parallel, $by, ['do' => 'taken', 'deal' => $deal->id, 'manager' => $deal->buyer_id, 'main' => $main->id,
            'from' => $main->buyer_id, 'stage' => $stage?->name, ...($sent ? ['reissue' => true] : [])]);

        $label = 'В работу: '.$deal->buyer?->shortName();
        if ($stage && $sent) {
            // Не откатом: у отката шаг письма не ждёт письма (`EnterStage`), а письмо «перевыставить» как раз и нужно.
            ($this->enter)($offer, Parallels::ready($deal) ? $letter : $buyer, $by, ['label' => $label.', перевыставить счёт']);
        } elseif ($stage && $buyer && ($stage->id === $buyer->id || ($stage->id === $letter?->id && ! Parallels::ready($deal)))) {
            // Шаг реквизитов — данные нового: вход заново заводит просьбу ему, готовые данные проводят шаг сам.
            ($this->enter)($offer, $buyer, $by, ['label' => $label]);
        } elseif ($stage && ! ($letter && $stage->id === $letter->id)) {
            $this->carry($offer, $stage, $deal, $shadow, $by);
        }
        if ($deal->agreed_at) {
            ($this->invoices)($deal->fresh(), $by);
        }
        Parallels::follow($offer->fresh(), $by);

        return ['deal' => $deal->fresh(), 'reissue' => $sent];
    }

    /**
     * Шаг до реквизитов: просьба шага — новому; на согласии, которое он уже дал у себя (его теневой шаг дальше), — проходит
     * сам его «Беру в работу».
     */
    private function carry(Offer $offer, Stage $stage, Deal $deal, ?Stage $shadow, User $by, bool $ask = true): void
    {
        if ($stage->asksConsent() && $shadow && Parallels::rank($shadow) > Parallels::rank($stage)
            && ($exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->takes()))) {
            ($this->takeExit)($offer, $exit, Actor::Manager, $deal->buyer, ['label' => 'Согласие дано раньше']);

            return;
        }
        if ($ask && $stage->awaitsManager($deal) && $deal->buyer) {
            $requirement = Requirement::askFor($deal, $stage, $offer->position(Track::Sale));
            $deal->buyer->notify(new YourTurnNotice($requirement->load(['offer', 'deal', 'stage'])));
            StepAsk::post($requirement);
        }
    }

    /** Идущей нет: параллельная входит в сделку, как «Отдать»; согласие, данное у себя, — проходит само. */
    private function promote(Offer $offer, Deal $deal, User $by): Deal
    {
        if (! in_array($offer->state, [OfferState::Open, OfferState::Draft, OfferState::Gallery], true)) {
            throw ValidationException::withMessages(['take' => 'ТС уже не в продаже — в работу не взять']);
        }
        $shadow = $deal->shadowStage;
        $deal->forceFill(['state' => DealState::Active, 'shadow_stage_id' => null, 'shadow_at' => null, 'agreed_at' => null])->save();
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'parallel'])]);
        $offer->log(OfferEventType::Parallel, $by, ['do' => 'taken', 'deal' => $deal->id, 'manager' => $deal->buyer_id]);
        $offer = $this->give->promote($offer, $deal->setRelation('offer', $offer), $by);
        $stage = $offer->unsetRelation('positions')->position(Track::Sale)?->stage;
        if ($stage) {
            // Просьбу шага вход в сделку уже завёл (`EnterStage`) — только согласие, данное у себя.
            $this->carry($offer, $stage, $deal->fresh(), $shadow, $by, ask: false);
        }

        return $deal->fresh();
    }
}
