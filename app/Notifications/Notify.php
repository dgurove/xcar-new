<?php

namespace App\Notifications;

use App\Chats\AuthorKind;
use App\Chats\Events\ChatMessagePosted;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OffersShown;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\BuyerJoined as BuyerJoinedMessage;
use App\Telegram\Messages\Registration;
use App\Users\Events\BuyerJoined;
use App\Users\Events\AccessDecided;
use App\Users\Events\UserRegistered;
use App\Users\Role;
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
            AccessDecided::class => 'accessDecided',
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

    /** Уведомление только о первом непрочитанном: дальше человек уже в чате. */
    public function chat(ChatMessagePosted $e): void
    {
        $chat = $e->message->chat;
        if ($e->message->author_kind === AuthorKind::Participant) {
            if ($chat->unread_for_staff === 1) {
                Notification::send($this->staff(), new ChatNotice($e->message, true));
            }
        } elseif ($chat->unread_for_user === 1) {
            $chat->user?->notify(new ChatNotice($e->message, false));
        }
    }

    private function staff()
    {
        return User::whereIn('role', [Role::Moderator, Role::Admin])->get();
    }
}
