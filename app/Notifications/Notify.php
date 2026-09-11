<?php

namespace App\Notifications;

use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Events\StageDue;
use App\Workflow\Events\StageEntered;
use App\Workflow\Requirement;
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
            StageEntered::class => 'stageEntered',
            StageDue::class => 'stageDue',
            \App\Chats\Events\ChatMessagePosted::class => 'chat',
        ];
    }

    public function offerPublished(OfferPublished $e): void
    {
        Notification::send(User::where('role', Role::Manager)->get(), new OfferPublishedNotice($e->offer));
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

    public function interest(InterestRegistered $e): void
    {
        Notification::send($this->staff(), new InterestNotice($e->interest->load('offer', 'user')));
    }

    public function stageEntered(StageEntered $e): void
    {
        $deal = $e->offer->deal()->with('buyer')->first();
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
    public function chat(\App\Chats\Events\ChatMessagePosted $e): void
    {
        $chat = $e->message->chat;
        if ($e->message->author_kind === \App\Chats\AuthorKind::Participant) {
            if ($chat->unread_for_staff === 1) {
                Notification::send($this->staff(), new ChatNotice($e->message, true));
            }
        } elseif ($chat->unread_for_user === 1) {
            $chat->user->notify(new ChatNotice($e->message, false));
        }
    }

    private function staff()
    {
        return User::whereIn('role', [Role::Moderator, Role::Admin])->get();
    }
}
