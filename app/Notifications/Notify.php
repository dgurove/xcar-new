<?php

namespace App\Notifications;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\ChargeKind;
use App\Billing\Events\AgentFeeDue;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\InvoiceOverdue;
use App\Billing\Events\OnlinePaymentTrouble;
use App\Billing\Events\PaidOnline;
use App\Billing\Events\PaymentClaimed;
use App\Billing\Events\PaymentConfirmed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentRejected;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Billing\Seller;
use App\Chats\AuthorKind;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Presence;
use App\Garage\Car as GarageCar;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Deal;
use App\Offers\Estimate;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\EstimateGiven;
use App\Offers\Events\EstimateRejected;
use App\Offers\Events\EstimatesAsked;
use App\Offers\Events\EstimatesClosed;
use App\Offers\Events\EstimateTaken;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OffersShown;
use App\Offers\Handover;
use App\Offers\Jobs\RetractEstimateNotices;
use App\Offers\OfferViewer;
use App\Park\City;
use App\Park\Events\BuyerFormSubmitted;
use App\Park\Events\CandidateArrived;
use App\Park\Events\LetterArrived;
use App\Park\Events\ReleasedWithoutQr;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleIdle;
use App\Park\Events\VehicleReleased;
use App\Park\Events\VehicleSold;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\AgentFeeDue as AgentFeeDueMessage;
use App\Telegram\Messages\BuyerJoined as BuyerJoinedMessage;
use App\Telegram\Messages\InvoiceNeeded as InvoiceNeededMessage;
use App\Telegram\Messages\ManagerJoined as ManagerJoinedMessage;
use App\Telegram\Messages\OnlinePayment as OnlinePaymentMessage;
use App\Telegram\Messages\ParkHandled;
use App\Telegram\Messages\PaymentClaimed as PaymentClaimedMessage;
use App\Telegram\Messages\RosTransferDue;
use App\Users\Events\AccessDecided;
use App\Users\Events\BuyerJoined;
use App\Users\Events\ManagerJoined;
use App\Users\Role;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Events\StageDue;
use App\Workflow\Events\StageEntered;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/** Кому что рассказать, когда в офферах что-то произошло. */
final class Notify
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            OfferPublished::class => 'offerPublished',
            BidPlaced::class => 'bidPlaced',
            BidAccepted::class => 'bidAccepted',
            EstimatesAsked::class => 'estimatesAsked',
            EstimateGiven::class => 'estimateGiven',
            EstimateRejected::class => 'estimateRejected',
            EstimateTaken::class => 'estimateTaken',
            EstimatesClosed::class => 'estimatesClosed',
            InterestRegistered::class => 'interest',
            OffersShown::class => 'offersShown',
            StageEntered::class => 'stageEntered',
            StageDue::class => 'stageDue',
            ChatMessagePosted::class => 'chat',
            BuyerJoined::class => 'buyerJoined',
            ManagerJoined::class => 'managerJoined',
            AccessDecided::class => 'accessDecided',
            CandidateArrived::class => 'candidateArrived',
            LetterArrived::class => 'parkMail',
            VehicleIdle::class => 'parkIdle',
            VehicleSold::class => 'parkSold',
            VehicleAccepted::class => 'parkHandled',
            VehicleReleased::class => 'parkHandled',
            BuyerFormSubmitted::class => 'parkBuyerForm',
            ReleasedWithoutQr::class => 'parkWithoutQr',
            InvoiceOverdue::class => 'invoiceOverdue',
            InvoiceIssued::class => 'invoiceIssued',
            PaymentClaimed::class => 'paymentClaimed',
            PaymentConfirmed::class => 'paymentConfirmed',
            PaymentRejected::class => 'paymentRejected',
            PaymentRecorded::class => 'paymentRecorded',
            AgentFeeDue::class => 'agentFeeDue',
            PaidOnline::class => 'paidOnline',
            OnlinePaymentTrouble::class => 'onlinePaymentTrouble',
        ];
    }

    /** Покупатель прошёл по ссылке: менеджеру — в ленту и push, владельцу — строка в Telegram. */
    public function buyerJoined(BuyerJoined $e): void
    {
        $e->user->manager?->notify(new BuyerJoinedNotice($e->user));
        NotifyOwner::dispatch(new BuyerJoinedMessage($e->user->load('manager')));
    }

    /** Менеджер пришёл по ссылке админа — владельцу строка в Telegram. */
    public function managerJoined(ManagerJoined $e): void
    {
        NotifyOwner::dispatch(new ManagerJoinedMessage($e->user));
    }

    public function accessDecided(AccessDecided $e): void
    {
        if ($e->approved) {
            $e->user->notify(new AccessOpenedNotice);
        }
    }

    /**
     * Новое предложение — тем, чья волна показа уже наступила (остальным напишут часы, NotifyViewers::due).
     * Снова в продаже (менеджер отказался) — снова всем, кто видит: отметки «писали» сбрасываются.
     * Вышло слотом — сразу не пишем: часы тем же тиком соберут слот в одно уведомление на человека.
     */
    public function offerPublished(OfferPublished $e): void
    {
        OfferViewer::where('offer_id', $e->offer->id)->update(['notified_at' => null]);
        if (! $e->slot) {
            app(NotifyViewers::class)($e->offer);
        }
    }

    /** Покупателям — одно уведомление на пачку: «открыл вам 3 автомобиля». */
    public function offersShown(OffersShown $e): void
    {
        foreach ($e->fresh as $buyerId => $offerIds) {
            User::find($buyerId)?->notify(new OffersShownNotice($e->manager, $offerIds));
        }
    }

    public function bidPlaced(BidPlaced $e): void
    {
        if ($e->byStaff) {
            return; // внёс сам админ — он и так знает
        }
        Notification::send($this->staff(), new BidPlacedNotice($e->bid->load('offer', 'user')));
    }

    public function bidAccepted(BidAccepted $e): void
    {
        $deal = $e->bid->offer->deal()->first();
        if ($deal) {
            $e->bid->user->notify(new BidAcceptedNotice($deal->load('offer')));
        }
    }

    /** Оценка менеджером (11.10.2026): просьба — менеджеру, оценка — админам, решение — снова менеджеру. */
    public function estimatesAsked(EstimatesAsked $e): void
    {
        foreach ($e->estimates as $estimate) {
            $estimate->loadMissing('user')->user?->notify(new EstimateAskedNotice($estimate));
        }
    }

    public function estimateGiven(EstimateGiven $e): void
    {
        Notification::send($this->staff(), new EstimateGivenNotice($e->estimate->loadMissing('offer', 'user')));
    }

    /** Отказ от оценки — админам тихо (`DeclineEstimate` зовёт напрямую). */
    public function estimateDeclined(Estimate $estimate): void
    {
        Notification::send($this->staff(), new EstimateDeclinedNotice($estimate));
    }

    public function estimateRejected(EstimateRejected $e): void
    {
        $e->estimate->loadMissing('offer', 'user')->user?->notify(new EstimateRejectedNotice($e->estimate));
    }

    public function estimateTaken(EstimateTaken $e): void
    {
        $e->estimate->loadMissing('offer', 'user')->user?->notify(new EstimateTakenNotice($e->estimate));
    }

    /** Просьбу закрыли — «Оцените» у менеджеров стирается из ленты и Telegram. */
    public function estimatesClosed(EstimatesClosed $e): void
    {
        RetractEstimateNotices::dispatch($e->estimateIds, $e->userIds, '/offers/'.$e->offer->number);
    }

    /** Интерес покупателя — его менеджеру; интерес посетителя — сотрудникам, как раньше. */
    public function interest(InterestRegistered $e): void
    {
        $interest = $e->interest->load('offer', 'user');
        // Внёс сотрудник (за менеджера) или админ отметил себя — сотрудники и так знают.
        if ($interest->placed_by || $interest->user->isAdmin()) {
            return;
        }
        if ($interest->user->isBuyer()) {
            $interest->user->manager?->notify(new BuyerInterestNotice($interest));

            return;
        }
        Notification::send($this->staff(), new InterestNotice($interest));
    }

    public function stageEntered(StageEntered $e): void
    {
        $deal = $e->deal ?? $e->offer->deal()->with('buyer')->first();
        // Дошли до оплаты без счёта (`EnterStage`): счета ставятся сами, так что ход наш — только когда гаражной не вписана
        // доля или счёт не встал (сотрудникам и владельцу — словами того, что нужно); ПРАЙМ без покупателя — ход менеджера.
        $position = $deal?->isActive() ? Position::where('offer_id', $e->offer->id)->where('track', Track::Sale)->with('stage.exits')->first() : null;
        if ($position?->awaitsInvoice()) {
            $this->invoiceNeeded($deal->setRelation('offer', $e->offer), $position->gap());
        } elseif ($position && $e->to->isPayStep() && $deal->buyer && in_array($deal->invoiceGap(), ['buyer', 'passport'], true)) {
            $deal->setRelation('offer', $e->offer)->buyer->notify(new BuyerNeededNotice($deal, $deal->invoiceGap() === 'passport'));

            return;
        }
        // Альфа оплачена — теперь можно забирать (10.10.2026): тому, кто забирает, контакт и адрес.
        if ($deal?->prepaid_at && $e->exit && $e->offer->vendor?->pay_before_pickup
            && in_array(mb_strtolower(trim($e->exit->label)), ['оплата получена', 'поставщику оплачено'], true)) {
            $this->pickupOpened($deal->setRelation('offer', $e->offer));
        }
        // Каркаде: поставщик согласовал заявку — теперь можно забирать (11.10.2026), до того менеджер только ждал.
        if ($deal && $e->exit && $e->offer->vendor?->deal_format === DealFormat::Wholesale
            && str_starts_with(mb_strtolower(trim($e->exit->label)), 'поставщик согласовал') && $deal->refresh()->agreed_at) {
            $this->pickupOpened($deal->setRelation('offer', $e->offer));
        }
        // Шаг сдвинуло письмо страховой — менеджеру пишет «Страховая ответила» с этим ходом (`AdvanceOnReply`).
        if (! $deal?->buyer || $e->letter) {
            return;
        }
        $requirement = Requirement::where('deal_id', $deal->id)->where('stage_id', $e->to->id)->whereNull('done_at')->latest()->first();
        // Поставщик согласовал, а забирает сам менеджер — сразу с кем говорить и куда ехать (04.10.2026).
        $agreed = $e->exit && str_starts_with(mb_strtolower($e->exit->label), 'поставщик согласовал');
        if ($requirement) {
            $deal->buyer->notify(new YourTurnNotice($requirement->load('offer')));
        } elseif ($agreed && $deal->buyerPicksUp() && ($handover = Handover::for($deal->setRelation('offer', $e->offer)))->open) {
            $deal->buyer->notify(new HandoverNotice($deal, $handover));
        } elseif ($e->from?->block_id !== $e->to->block_id && $deal->created_at->lt(now()->subMinute())) {
            // Первый шаг только что открытой сделки не пишем: менеджеру уже идёт «подтверждение принято».
            $deal->buyer->notify(new DealStepNotice($deal->load('offer'), $e->to));
        }
    }

    /** Счёт Р.О.С. оплачен целиком — сотрудникам громко и владельцу в Telegram: перевести Альфе (10.10.2026). */
    private function rosPaid(Invoice $i): void
    {
        if ($i->seller !== Seller::Ros || $i->direction !== 'issued' || $i->state !== InvoiceState::Paid || ! $i->deal_id) {
            return;
        }
        $i->load(['party', 'deal.offer.vendor']);
        Notification::send($this->staff(), MoneyNotice::rosReceived($i));
        NotifyOwner::dispatch(new RosTransferDue($i));
    }

    /**
     * Можно забирать: менеджеру сделки, что забирает сам, — контакт владельца (его вывоз в логистике до того молчал,
     * `NotifyLogistics::assigned`). Остальным исполнителям задача сама показывает, что уже можно.
     */
    private function pickupOpened(Deal $deal): void
    {
        if ($deal->buyerPicksUp() && ($handover = Handover::for($deal))->open) {
            $deal->buyer?->notify(new HandoverNotice($deal, $handover));
        }
    }

    public function stageDue(StageDue $e): void
    {
        $stage = $e->position->stage;
        if ($e->position->waitsFor() === WaitsFor::Manager) {
            // Менеджеру — пока его просьба на этапе не выполнена: ответил — напоминать не о чем.
            $deal = $e->offer->deal()->with('buyer')->first();
            if ($deal?->buyer && Requirement::where('deal_id', $deal->id)->where('stage_id', $stage->id)->whereNull('done_at')->exists()) {
                $deal->buyer->notify(new StageDueNotice($e->offer, $e->position, $e->overdue, $deal->id));
            }
        }
        // Сотрудникам (19.01.2027: 598 уведомлений о сроках за неделю, по 28 в день на админа) — «скоро» только когда ход наш,
        // чужой ход (менеджер, поставщик) — лишь «срок вышел»; утром — одна строка «Горит» (`StagesDigest`).
        $waits = $e->position->waitsFor();
        if ($waits === WaitsFor::Us || ($e->overdue && $waits !== WaitsFor::Nobody)) {
            Notification::send($this->staff(), new StageDueNotice($e->offer, $e->position, $e->overdue));
        }
    }

    /**
     * Каждое сообщение — пушем (первое непрочитанное — ещё в ленту и на почту); тому, у кого
     * этот чат сейчас на экране (Chat presence), — ничего: он его уже видит.
     */
    public function chat(ChatMessagePosted $e): void
    {
        $chat = $e->message->chat;
        if ($e->message->author_kind === AuthorKind::Participant) {
            // Вторая сторона: менеджер покупателя или сотрудники площадки.
            $to = $chat->manager_id ? collect([$chat->manager])->filter() : $this->staff();
            $notice = new ChatNotice($e->message, true, $chat->unread_for_staff === 1);
        } else {
            $to = collect([$chat->user])->filter();
            $notice = new ChatNotice($e->message, false, $chat->unread_for_user === 1);
        }
        Notification::send($to->reject(fn (User $u) => Presence::viewing($chat, $u)), $notice);
    }

    /**
     * Новая цепочка «Из писем». Парковка — управляющим её города в ленту, пуш и Telegram. CRM — модераторам
     * с почтой «Новое из писем», пока цепочка ждёт (завели раньше, чем ушло, — не шлём).
     */
    public function candidateArrived(CandidateArrived $e): void
    {
        $c = $e->candidate;
        if ($c->scope === Scope::Park) {
            // Заявку уже завела почта — уведомление о ней, со ссылкой на дело, а не в «Из писем».
            $request = $c->vehicle_id ? ParkRequest::where('vehicle_id', $c->vehicle_id)->whereIn('state', RequestState::open())->latest('id')->first() : null;
            Notification::send($this->parkManagers(City::ofCandidate($c)), $request ? ParkNotice::arrived($request) : ParkNotice::letter($c));

            return;
        }
        $c = $c->fresh();
        $moderators = User::withRole(Role::Moderator)->get()->filter->canCrmMail();
        // Письмо само стало черновиком (`AutoOffer`) — о черновике, а не «завести из писем».
        if ($c?->state === CandidateState::Promoted && ($offer = $c->offer) && $offer->isAutoDraft()) {
            Notification::send($moderators, new OfferDraftNotice($offer->load('vendor')));

            return;
        }
        if ($c?->state !== CandidateState::New) {
            return;
        }
        Notification::send($moderators, new OfferLetterNotice($c));
    }

    /** Письмо в ветку привязанной ТС — управляющим её города (исполнителя у заявки нет с 10.10.2026). */
    public function parkMail(LetterArrived $e): void
    {
        Notification::send($this->parkManagers($e->vehicle->city_id), ParkNotice::mail($e->vehicle, $e->message));
    }

    /** Продано: управляющим города ТС. */
    public function parkSold(VehicleSold $e): void
    {
        $v = $e->vehicle->load(['vendor']);
        Notification::send($this->parkManagers($v->city_id), ParkNotice::sold($v, $e->message));
    }

    /** Покупатель заполнил анкету: управляющим города — прочитать ответ страховой и подтвердить. */
    public function parkBuyerForm(BuyerFormSubmitted $e): void
    {
        $v = $e->pass->vehicle->load([]);
        Notification::send($this->parkManagers($v->city_id), ParkNotice::buyerForm($v, $e->pass->name, $e->pass->pickup_on));
    }

    /**
     * Управляющий принял или выдал ТС — администраторам парковки строкой в ленту и в Telegram (владелец 09.10.2026: о
     * заявках админам не надо, «максимум о том, что управляющий выдал или принял»). Сами админы себе не пишут; выдача по
     * письмам и откаты (без заявки) — не действие управляющего.
     */
    public function parkHandled(VehicleAccepted|VehicleReleased $e): void
    {
        if (! $e->request || ! $e->by?->isParking() || $e->by->isParkAdmin()) {
            return;
        }
        $v = $e->vehicle->load(['vendor', 'yard']);
        $released = $e instanceof VehicleReleased;
        Notification::send(User::withRole(Role::ParkAdmin)->whereNotNull('approved_at')->get(), ParkNotice::handled($v, $e->by, $released));
        NotifyOwner::dispatch(new ParkHandled($v, $e->by, $released), park: true);
    }

    public function parkWithoutQr(ReleasedWithoutQr $e): void
    {
        NotifyOwner::dispatch(new \App\Telegram\Messages\ReleasedWithoutQr($e->vehicle->load(['vendor']), $e->by, $e->reason), park: true);
    }

    public function parkIdle(VehicleIdle $e): void
    {
        Notification::send($this->parkStaff()->filter->isParkAdmin(), ParkNotice::idle($e->vehicle, $e->days));
    }

    /**
     * Просроченный счёт: CRM — админам, парковки — управляющим её города и админам. Владельцу в Telegram — нет (18.10.2026,
     * владелец: сводки там «бесят, бесполезные»); кнопка «Оплачен» у прежних сообщений работает (`UpdateHandler`).
     */
    public function invoiceOverdue(InvoiceOverdue $e): void
    {
        $invoice = $e->invoice->load(['party', 'vehicle', 'deal.offer']);
        if (! $invoice->park) {
            Notification::send($this->staff(), MoneyNotice::overdue($invoice));

            return;
        }
        Notification::send($this->parkStaff(City::ofInvoice($invoice))->filter->canManagePark(), ParkNotice::overdue($invoice));
    }

    /** Счёт по сделке выставлен — менеджеру сделки (платит он или его покупатель — всё равно ему). */
    public function invoiceIssued(InvoiceIssued $e): void
    {
        $i = $e->invoice;
        if ($i->isOwed() || ! $i->deal_id || $i->kind === ChargeKind::Reward) {
            return;
        }
        $i->load(['party', 'deal.offer', 'deal.buyer']);
        $i->deal?->buyer?->notify(MoneyNotice::invoiceIssued($i));
    }

    /** Менеджер сообщил об оплате: сотрудникам в ленту, владельцу в Telegram с «Поступило». */
    public function paymentClaimed(PaymentClaimed $e): void
    {
        $p = $e->payment->load(['invoice.party', 'invoice.deal.offer', 'invoice.deal.buyer']);
        Notification::send($this->staff(), MoneyNotice::claimed($p));
        NotifyOwner::dispatch(new PaymentClaimedMessage($p));
    }

    public function paymentConfirmed(PaymentConfirmed $e): void
    {
        // «Перевести Альфе» шлёт `paymentRecorded`: подтверждение заявки — тоже запись оплаты, второй раз не надо.
        $p = $e->payment->load(['invoice.deal.offer', 'invoice.deal.buyer']);
        ($p->invoice->deal?->buyer ?? GarageCar::ofInvoice($p->invoice)?->manager)?->notify(MoneyNotice::paymentConfirmed($p));
    }

    public function paymentRejected(PaymentRejected $e): void
    {
        $p = $e->payment->load(['invoice.deal.offer', 'invoice.deal.buyer']);
        ($p->invoice->deal?->buyer ?? GarageCar::ofInvoice($p->invoice)?->manager)?->notify(MoneyNotice::paymentRejected($p));
    }

    /** Выплата менеджеру записана — ему в ленту. Оплаты покупателя тут не касаются: о них он узнаёт подтверждением заявки. */
    public function paymentRecorded(PaymentRecorded $e): void
    {
        $i = $e->invoice;
        $this->rosPaid($i);
        $garage = $i->deal_id ? null : GarageCar::ofInvoice($i);
        if (! $i->isAgentFee() || (! $i->deal_id && ! $garage)) {
            return;
        }
        $p = $i->payments()->latest('id')->first();
        if ($p && $p->source !== PaymentSource::Offset) {
            $i->load(['deal.offer', 'deal.buyer']);
            $p->setRelation('invoice', $i);
            ($i->deal?->buyer ?? $garage?->manager)?->notify(MoneyNotice::payout($p));
        }
    }

    /** Оплатили по ссылке: менеджеру счёта, сотрудникам в «Деньги», владельцу строкой в Telegram (05.10.2026: «непонятно, оплатили или нет»). */
    public function paidOnline(PaidOnline $e): void
    {
        $p = $e->payment->load(['invoice.party', 'invoice.deal.offer', 'invoice.deal.buyer']);
        ($p->invoice->deal?->buyer ?? GarageCar::ofInvoice($p->invoice)?->manager)?->notify(MoneyNotice::paidOnline($e->link, $p));
        Notification::send($this->staff(), MoneyNotice::paidOnlineStaff($e->link, $p));
        if ($attempt = AcquiringPayment::where('payment_id', $p->id)->with('link.invoice')->first()) {
            NotifyOwner::dispatch(new OnlinePaymentMessage($attempt, 'paid'));
        }
    }

    /**
     * Банк отклонил оплату — менеджеру счёта (он скажет покупателю); переплата и непробитый чек — сотрудникам в ленту
     * и владельцу в Telegram: вернуть деньги и разобраться с чеком может только он.
     */
    public function onlinePaymentTrouble(OnlinePaymentTrouble $e): void
    {
        $a = $e->attempt->load(['link.invoice.party', 'link.invoice.deal.offer', 'link.invoice.deal.buyer']);
        $i = $a->link->invoice;
        if ($e->what === 'declined') {
            ($i->deal?->buyer ?? GarageCar::ofInvoice($i)?->manager)?->notify(MoneyNotice::declined($a));

            return;
        }
        Notification::send($this->staff(), MoneyNotice::onlineTroubleStaff($a, $e->what));
        NotifyOwner::dispatch(new OnlinePaymentMessage($a, $e->what));
    }

    /** Вознаграждение к выплате: сотрудникам в ленту, владельцу в Telegram с «Выплачено». */
    public function agentFeeDue(AgentFeeDue $e): void
    {
        $fee = $e->fee->load(['party', 'deal.offer']);
        Notification::send($this->staff(), MoneyNotice::feeDue($fee));
        NotifyOwner::dispatch(new AgentFeeDueMessage($fee));
    }

    /** Люди парковки; с городом объекта — только те, кому он виден (`Park\City`: администраторы и управляющие без города — все). */
    /**
     * Заявки, письма, звонки, сроки, продажа и анкета — управляющим (роль «Парковка») своего города, не администраторам
     * парковки (владелец 09.10.2026); им — только «управляющий принял / выдал» (`parkHandled`). Управляющего у города нет
     * (новая парковка, роль сняли) — администраторам: заявка не должна лечь молча.
     */
    private function parkManagers(?int $city = null)
    {
        $staff = $this->parkStaff($city);
        $managers = $staff->filter->isParking()->values();

        return $managers->isNotEmpty() ? $managers : $staff->filter->isParkAdmin()->values();
    }

    private function parkStaff(?int $city = null)
    {
        return User::parkStaff()->whereNotNull('approved_at')->get()->filter(fn (User $u) => ! $city || ! ($own = City::of($u)) || $own === $city)->values();
    }

    /** Ход наш на оплате без счёта: сотрудникам и владельцу — что нужно (выставить, вписать долю, назначить вознаграждение). */
    public function invoiceNeeded(Deal $deal, ?string $gap): void
    {
        $deal->loadMissing(['buyer', 'offer']);
        Notification::send($this->staff(), new InvoiceNeededNotice($deal, $gap));
        NotifyOwner::dispatch(new InvoiceNeededMessage($deal, $gap));
    }

    /** Сторона площадки: подтверждения, интерес, сроки, деньги, чаты — админам; модератору их нет. */
    private function staff()
    {
        return User::withRole(Role::Admin)->get();
    }
}
