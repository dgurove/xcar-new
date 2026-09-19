<?php

namespace App\Notifications;

use App\Billing\Events\InvoiceOverdue;
use App\Chats\AuthorKind;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Presence;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OffersShown;
use App\Park\Events\CandidateArrived;
use App\Park\Events\LetterArrived;
use App\Park\Events\RequestAssigned;
use App\Park\Events\RequestCall;
use App\Park\Events\RequestDue;
use App\Park\Events\VehicleIdle;
use App\Park\Events\VehicleSold;
use App\Park\RequestState;
use App\Support\Money;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BuyerJoined as BuyerJoinedMessage;
use App\Telegram\Messages\ManagerJoined as ManagerJoinedMessage;
use App\Telegram\Messages\ParkLetter;
use App\Telegram\Messages\ParkSold;
use App\Telegram\Messages\Registration;
use App\Users\Events\AccessDecided;
use App\Users\Events\BuyerJoined;
use App\Users\Events\ManagerJoined;
use App\Users\Events\UserRegistered;
use App\Users\Role;
use App\Users\Section;
use App\Users\User;
use App\Workflow\Events\StageDue;
use App\Workflow\Events\StageEntered;
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
            BidDeclined::class => 'bidDeclined',
            InterestRegistered::class => 'interest',
            OffersShown::class => 'offersShown',
            StageEntered::class => 'stageEntered',
            StageDue::class => 'stageDue',
            ChatMessagePosted::class => 'chat',
            UserRegistered::class => 'registered',
            BuyerJoined::class => 'buyerJoined',
            ManagerJoined::class => 'managerJoined',
            AccessDecided::class => 'accessDecided',
            CandidateArrived::class => 'parkLetter',
            LetterArrived::class => 'parkMail',
            RequestAssigned::class => 'parkAssigned',
            RequestDue::class => 'parkDue',
            RequestCall::class => 'parkCall',
            VehicleIdle::class => 'parkIdle',
            VehicleSold::class => 'parkSold',
            InvoiceOverdue::class => 'invoiceOverdue',
        ];
    }

    /** Новый человек — владельцу в Telegram с кнопками решения. */
    public function registered(UserRegistered $e): void
    {
        NotifyOwner::dispatch(new Registration($e->user));
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

    public function offerPublished(OfferPublished $e): void
    {
        Notification::send($e->offer->allowedManagers(), new OfferPublishedNotice($e->offer));
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
        Notification::send($this->staff(), new BidPlacedNotice($e->bid->load('offer', 'user')));
    }

    public function bidAccepted(BidAccepted $e): void
    {
        $deal = $e->bid->offer->deal()->first();
        if ($deal) {
            $e->bid->user->notify(new BidAcceptedNotice($deal->load('offer')));
        }
    }

    public function bidDeclined(BidDeclined $e): void
    {
        $e->bid->user->notify(new BidDeclinedNotice($e->bid->load('offer')));
    }

    /** Интерес покупателя — его менеджеру; интерес посетителя — сотрудникам, как раньше. */
    public function interest(InterestRegistered $e): void
    {
        $interest = $e->interest->load('offer', 'user');
        if ($interest->user->isBuyer()) {
            $interest->user->manager?->notify(new BuyerInterestNotice($interest));

            return;
        }
        Notification::send($this->staff(), new InterestNotice($interest));
    }

    public function stageEntered(StageEntered $e): void
    {
        // Вывоз — наша работа: менеджеру про шаги эвакуатора не пишем.
        if ($e->track === Track::Service) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->with('buyer')->first();
        if (! $deal?->buyer) {
            return;
        }
        $requirement = Requirement::where('deal_id', $deal->id)->where('stage_id', $e->to->id)->whereNull('done_at')->latest()->first();
        if ($requirement) {
            $deal->buyer->notify(new YourTurnNotice($requirement->load('offer')));
        } elseif ($e->from?->block_id !== $e->to->block_id) {
            $deal->buyer->notify(new DealStepNotice($deal->load('offer'), $e->to));
        }
    }

    public function stageDue(StageDue $e): void
    {
        $stage = $e->position->stage;
        if ($stage->waits_for === WaitsFor::Manager) {
            $deal = $e->offer->deal()->with('buyer')->first();
            $deal?->buyer?->notify(new StageDueNotice($e->offer, $e->position, $e->overdue, $deal->id));
        }
        Notification::send($this->staff(), new StageDueNotice($e->offer, $e->position, $e->overdue));
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

    /** Письмо на стоянку — всем со стоянки в ленту и пуш, владельцу — строка в Telegram. */
    public function parkLetter(CandidateArrived $e): void
    {
        Notification::send($this->parkStaff(), ParkNotice::letter($e->candidate));
        NotifyOwner::dispatch(new ParkLetter($e->candidate));
    }

    /** Письмо в ветку привязанной ТС — исполнителю её открытой заявки, без него — всем со стоянки. */
    public function parkMail(LetterArrived $e): void
    {
        $assignee = $e->vehicle->requests()->whereIn('state', RequestState::open())->whereNotNull('assignee_id')->latest()->first()?->assignee;
        Notification::send($assignee ? collect([$assignee]) : $this->parkStaff(), ParkNotice::mail($e->vehicle, $e->message));
    }

    public function parkAssigned(RequestAssigned $e): void
    {
        $e->assignee->notify(ParkNotice::assigned($e->request->load('vehicle')));
    }

    /** Срок заявки — исполнителю, без него — всем со стоянки. */
    public function parkCall(RequestCall $e): void
    {
        $r = $e->request->load(['vehicle', 'assignee']);
        Notification::send($r->assignee ? collect([$r->assignee]) : $this->parkStaff(), ParkNotice::call($r));
    }

    /** Продано: сотруднику, который ведёт ТС (иначе всем на стоянке), и владельцу в Telegram. */
    public function parkSold(VehicleSold $e): void
    {
        $v = $e->vehicle->load(['brand', 'model', 'vendor']);
        $assignee = $v->requests()->whereIn('state', RequestState::open())->whereNotNull('assignee_id')->latest()->first()?->assignee;
        Notification::send($assignee ? collect([$assignee]) : $this->parkStaff(), ParkNotice::sold($v, $e->message));
        NotifyOwner::dispatch(new ParkSold($v));
    }

    public function parkDue(RequestDue $e): void
    {
        $r = $e->request->load(['vehicle', 'assignee']);
        Notification::send($r->assignee ? collect([$r->assignee]) : $this->parkStaff(), ParkNotice::due($r, $e->overdue));
    }

    public function parkIdle(VehicleIdle $e): void
    {
        Notification::send($this->parkStaff()->filter->isAdmin(), ParkNotice::idle($e->vehicle, $e->days));
    }

    /** Просроченный счёт — владельцу в Telegram с кнопкой «Оплачен», админам — в ленту. */
    public function invoiceOverdue(InvoiceOverdue $e): void
    {
        $invoice = $e->invoice->load(['party', 'vehicle.brand', 'vehicle.model']);
        NotifyOwner::dispatch(new \App\Telegram\Messages\InvoiceOverdue($invoice));
        Notification::send($this->parkStaff()->filter->isAdmin(), new ParkNotice(
            ($invoice->isOwed() ? 'Мы просрочили ' : 'Просрочен счёт ').$invoice->label().' — '.$invoice->party->name,
            Money::rub($invoice->remaining()), '/money/invoices/'.$invoice->id, $invoice->vehicle_id, true));
    }

    private function parkStaff()
    {
        return User::where(fn ($q) => $q->where('role', Role::Admin)->orWhereJsonContains('access', Section::Park->value))->whereNotNull('approved_at')->get();
    }

    private function staff()
    {
        return User::whereIn('role', [Role::Moderator, Role::Admin])->get();
    }
}
