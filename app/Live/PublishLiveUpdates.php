<?php

namespace App\Live;

use App\Chats\Events\ChatMessagePosted;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Events\OffersHidden;
use App\Offers\Events\OffersShown;
use App\Offers\Showing;
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
            OffersShown::class => 'shown',
            OffersHidden::class => 'hidden',
            StageEntered::class => 'stage',
            NotificationSent::class => 'notification',
            ChatMessagePosted::class => 'chat',
        ];
    }

    public function offer(OfferPublished|OfferStateChanged $e): void
    {
        $n = $e->offer->number;
        // Покупатели каталог не слушают — карточка и страница едут им в личные темы: продажа снимает её и у них.
        $buyers = Showing::buyerIdsOf($e->offer)->map(fn ($id) => Topics::user($id))->all();
        $this->publish->card($n, [Topics::CATALOG, ...$buyers]);
        $this->publish->refresh([Topics::CATALOG, ...$buyers], ["/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/', '/rabota/sdelki']);
    }

    /** Покупателю открыли предложения: его лента и сводка перечитываются, у менеджера — карточки с числом «видят». */
    public function shown(OffersShown $e): void
    {
        foreach (array_keys($e->fresh) as $buyerId) {
            $this->publish->refresh(Topics::user($buyerId), ['/', '/lk']);
        }
        $this->publish->refresh(Topics::user($e->manager), ['/', '/lk/pokupateli']);
    }

    public function hidden(OffersHidden $e): void
    {
        foreach ($e->gone as $buyerId => $numbers) {
            foreach ($numbers as $n) {
                $this->publish->card($n, Topics::user($buyerId));
            }
            $this->publish->refresh(Topics::user($buyerId), array_map(fn ($n) => "/offers/{$n}", $numbers));
        }
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
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/rabota/sdelki']);
    }

    public function interest(InterestRegistered $e): void
    {
        $n = $e->interest->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}"]);
        // Интерес покупателя — менеджеру: страница оффера и его список интересов.
        if ($manager = $e->interest->user->manager_id) {
            $this->publish->refresh(Topics::user($manager), ["/offers/{$n}", '/lk/interes', "/lk/pokupateli/{$e->interest->user_id}"]);
            $this->publish->badges(Topics::user($manager));
        }
    }

    public function stage(StageEntered $e): void
    {
        $n = $e->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/predlozheniya/{$n}", '/rabota/sdelki']);
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
        if ($chat->user_id) {
            $this->publish->badges(Topics::user($chat->user_id));
        }
        $this->publish->refresh(Topics::STAFF, ['/rabota/chaty']);
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
