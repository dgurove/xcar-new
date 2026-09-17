<?php

namespace App\Live;

use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Events\ChatRead;
use App\Notifications\ChatNotice;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OffersHidden;
use App\Offers\Events\OffersShown;
use App\Offers\Events\OfferStateChanged;
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
            ChatMessageChanged::class => 'chatChanged',
            ChatRead::class => 'chatRead',
        ];
    }

    public function offer(OfferPublished|OfferStateChanged $e): void
    {
        $n = $e->offer->number;
        // Покупатели каталог не слушают — карточка и страница едут им в личные темы: продажа снимает её и у них.
        $buyers = Showing::buyerIdsOf($e->offer)->map(fn ($id) => Topics::user($id))->all();
        $this->publish->card($n, [Topics::CATALOG, ...$buyers]);
        $this->publish->refresh([Topics::CATALOG, ...$buyers], ["/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/', '/work/deals']);
    }

    /** Покупателю открыли предложения: его лента и сводка перечитываются, у менеджера — карточки с числом «видят». */
    public function shown(OffersShown $e): void
    {
        foreach (array_keys($e->fresh) as $buyerId) {
            $this->publish->refresh(Topics::user($buyerId), ['/', '/account']);
        }
        $this->publish->refresh(Topics::user($e->manager), ['/', '/account/buyers']);
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
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/']);
        $this->publish->refresh(Topics::CATALOG, ["/offers/{$n}"]);
    }

    public function bidDecided(BidAccepted|BidDeclined $e): void
    {
        $n = $e->bid->offer->number;
        $this->publish->refresh(Topics::user($e->bid->user_id), ['/account/deals', "/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/work/deals']);
    }

    public function interest(InterestRegistered $e): void
    {
        $n = $e->interest->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        // Интерес покупателя — менеджеру: страница оффера и его список интересов.
        if ($manager = $e->interest->user->manager_id) {
            $this->publish->refresh(Topics::user($manager), ["/offers/{$n}", '/account/interest', "/account/buyers/{$e->interest->user_id}"]);
            $this->publish->badges(Topics::user($manager));
        }
    }

    public function stage(StageEntered $e): void
    {
        $n = $e->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/work/deals']);
        if ($deal = $e->deal ?? $e->offer->deal()->first()) {
            $this->publish->refresh(Topics::user($deal->buyer_id), ['/account/deals', "/account/deals/{$deal->id}"]);
        }
    }

    /**
     * Новое сообщение: обеим сторонам {chat, seq} — лента догоняет; с превью (кто, текст, куда) —
     * для тоста тому, у кого этот чат не на экране. Сотрудникам, читающим чужой чат в CRM, — тоже.
     */
    public function chat(ChatMessagePosted $e): void
    {
        $m = $e->message;
        $chat = $m->chat;
        $data = ['chat' => $chat->id, 'seq' => $m->seq, 'author' => $m->author_id, 'text' => $m->preview(90)];
        $other = $this->otherSide($chat);
        $sides = [$other, $chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id)];
        // Кому какой адрес: участнику — его экран, второй стороне — свой; тост берёт по своей теме.
        ($this->publish)($sides[1], 'chat', $data + ['from' => $chat->manager?->shortName() ?? Chat::PLATFORM, 'href' => ChatNotice::hrefFor($chat, false)]);
        ($this->publish)($other, 'chat', $data + ['from' => $chat->displayName(), 'href' => ChatNotice::hrefFor($chat, true)]);
        if ($chat->manager_id) {
            ($this->publish)(Topics::STAFF, 'chat', $data);
        }
        $this->publish->badges($other);
        if ($chat->user_id) {
            $this->publish->badges(Topics::user($chat->user_id));
        }
        $this->publish->refresh($other, [$chat->manager_id ? '/account/chats' : '/work/chats']);
        if ($chat->user_id) {
            $this->publish->refresh(Topics::user($chat->user_id), ['/account/chats']);
        }
    }

    /** Правка или удаление — обеим сторонам перечитать один пузырь. */
    public function chatChanged(ChatMessageChanged $e): void
    {
        $chat = $e->message->chat;
        ($this->publish)($this->allSides($chat), 'chat-edit', ['chat' => $chat->id, 'seq' => $e->message->seq]);
        $this->publish->refresh($this->otherSide($chat), [$chat->manager_id ? '/account/chats' : '/work/chats']);
    }

    /** Сторона дочитала — другой стороне двойные галочки. */
    public function chatRead(ChatRead $e): void
    {
        $chat = $e->chat;
        $topic = $e->byCounterpart ? ($chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id)) : $this->otherSide($chat);
        ($this->publish)($topic, 'chat-read', ['chat' => $chat->id, 'seq' => $e->seq]);
    }

    /** Вторая сторона — менеджер покупателя или сотрудники площадки. */
    private function otherSide(Chat $chat): string
    {
        return $chat->manager_id ? Topics::user($chat->manager_id) : Topics::STAFF;
    }

    /** Обе стороны и сотрудники (они читают любой чат). */
    private function allSides(Chat $chat): array
    {
        return array_unique([$this->otherSide($chat), $chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id), Topics::STAFF]);
    }

    public function notification(NotificationSent $e): void
    {
        if ($e->channel !== 'database' || ! $e->notifiable instanceof User) {
            return;
        }
        // Сообщение чата тостом показывает сам live-канал (событие chat) — второй раз не надо.
        if ($e->notification instanceof ChatNotice) {
            return;
        }
        $topic = Topics::user($e->notifiable);
        $this->publish->toast($topic, $e->notification->title(), $e->notification->href());
        $this->publish->badges($topic);
    }
}
