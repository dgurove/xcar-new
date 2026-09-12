<?php

namespace App\Live;

use App\Chats\Events\ChatMessagePosted;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Users\User;
use App\Workflow\Events\StageEntered;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSent;

/** Событие в приложении → сообщение в хаб. Клиент по нему перечитывает фрагмент или страницу. */
final class PublishLiveUpdates
{
    public function __construct(private Publisher $publish) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            OfferPublished::class => 'offer',
            OfferStateChanged::class => 'offer',
            BidPlaced::class => 'bid',
            BidAccepted::class => 'bidDecided',
            BidDeclined::class => 'bidDecided',
            InterestRegistered::class => 'interest',
            StageEntered::class => 'stage',
            NotificationSent::class => 'notification',
            ChatMessagePosted::class => 'chat',
        ];
    }

    public function offer(OfferPublished|OfferStateChanged $e): void
    {
        $n = $e->offer->number;
        $this->publish->card($n);
        $this->publish->refresh(Topics::CATALOG, ["/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/', '/sdelki']);
    }

    public function bid(BidPlaced $e): void
    {
        $n = $e->bid->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/']);
        $this->publish->refresh(Topics::CATALOG, ["/offers/{$n}"]);
    }

    public function bidDecided(BidAccepted|BidDeclined $e): void
    {
        $n = $e->bid->offer->number;
        $this->publish->refresh(Topics::user($e->bid->user_id), ['/lk/stavki', '/lk/sdelki', "/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/sdelki']);
    }

    public function interest(InterestRegistered $e): void
    {
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$e->interest->offer->number}"]);
    }

    public function stage(StageEntered $e): void
    {
        $n = $e->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/sdelki']);
        if ($deal = $e->deal ?? $e->offer->deal()->first()) {
            $this->publish->refresh(Topics::user($deal->buyer_id), ['/lk/sdelki', "/lk/sdelki/{$deal->id}"]);
        }
    }

    public function chat(ChatMessagePosted $e): void
    {
        $chat = $e->message->chat;
        $data = ['chat' => $chat->id, 'seq' => $e->message->seq];
        $topics = [Topics::STAFF, $chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id)];
        ($this->publish)($topics, 'chat', $data);
        $this->publish->badges(Topics::STAFF);
        $this->publish->refresh(Topics::STAFF, ['/perepiski/chaty']);
    }

    public function notification(NotificationSent $e): void
    {
        if ($e->channel !== 'database' || ! $e->notifiable instanceof User) {
            return;
        }
        $topic = Topics::user($e->notifiable);
        $this->publish->toast($topic, $e->notification->title(), $e->notification->href());
        $this->publish->badges($topic);
    }
}
