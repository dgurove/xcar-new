<?php

namespace App\Live;

use App\Billing\Events\PaymentClaimed;
use App\Billing\Events\PaymentRecorded;
use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Events\ChatRead;
use App\Garage\Car as GarageCar;
use App\Garage\Events\GarageChanged;
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
            GarageChanged::class => 'garage',
            PaymentRecorded::class => 'payment',
            PaymentClaimed::class => 'payment',
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
            $this->publish->refresh(Topics::user($buyerId), ['/offers', '/account']);
        }
        $this->publish->refresh(Topics::user($e->manager), ['/offers', '/buyers']);
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
        $this->publish->refresh(Topics::user($e->bid->user_id), ['/deals', "/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/work/deals']);
    }

    public function interest(InterestRegistered $e): void
    {
        $n = $e->interest->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        // Интерес покупателя — менеджеру: страница оффера и его список интересов.
        if ($manager = $e->interest->user->manager_id) {
            $this->publish->refresh(Topics::user($manager), ["/offers/{$n}", '/buyers/interest', "/buyers/{$e->interest->user_id}"]);
            $this->publish->badges(Topics::user($manager));
        }
    }

    public function stage(StageEntered $e): void
    {
        $n = $e->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}", '/work/deals', '/work/garage', '/work/pickups']);
        // Вывоз, порученный менеджеру: его «Гараж» и страница вывоза.
        if ($e->track === \App\Workflow\Track::Service && $e->offer->evacuator_id) {
            $this->publish->refresh(Topics::user($e->offer->evacuator_id), ['/garage', "/garage/pickups/{$n}"]);
        }
        if ($deal = $e->deal ?? $e->offer->deal()->first()) {
            // Гаражная сделка живёт в гараже: шаг со страховой менеджер видит в карточке машины.
            $this->publish->refresh(Topics::user($deal->buyer_id), $deal->isGarage() ? ['/garage', "/garage/cars/{$n}"] : ['/deals', "/deals/{$deal->id}"]);
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
        $this->publish->refresh($other, $chat->manager_id ? ['/account/chats'] : ['/work/chats', '/account/chats']);
        if ($chat->user_id) {
            $this->publish->refresh(Topics::user($chat->user_id), ['/account/chats']);
        }
    }

    /** Правка или удаление — обеим сторонам перечитать один пузырь. */
    public function chatChanged(ChatMessageChanged $e): void
    {
        $chat = $e->message->chat;
        ($this->publish)($this->allSides($chat), 'chat-edit', ['chat' => $chat->id, 'seq' => $e->message->seq]);
        $this->publish->refresh($this->otherSide($chat), $chat->manager_id ? ['/account/chats'] : ['/work/chats', '/account/chats']);
    }

    /**
     * Сторона дочитала — другой стороне двойные галочки, своей — значки и список чатов: прочитанное гаснет и в
     * соседней вкладке, и в списке слева на ПК, и на значке приложения.
     */
    public function chatRead(ChatRead $e): void
    {
        $chat = $e->chat;
        $participant = $chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id);
        $reader = $e->byCounterpart ? $this->otherSide($chat) : $participant;
        ($this->publish)($e->byCounterpart ? $participant : $this->otherSide($chat), 'chat-read', ['chat' => $chat->id, 'seq' => $e->seq]);
        $this->publish->badges($reader);
        $this->publish->refresh($reader, $e->byCounterpart && ! $chat->manager_id ? ['/work/chats', '/account/chats'] : ['/account/chats']);
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

    /** Гараж: машину и список машин перечитывают сотрудники и её менеджер. */
    public function garage(GarageChanged $e): void
    {
        $paths = ['/garage', '/garage/cars/'.$e->car->offer->number];
        $this->publish->refresh(Topics::STAFF, [...$paths, '/work/garage', '/offers/'.$e->car->offer->number]);
        if ($e->car->manager_id) {
            $this->publish->refresh(Topics::user($e->car->manager_id), $paths);
        }
    }

    /** Оплата по счёту (по ссылке, из выписки, руками) или заявка — перечитать деньги у сотрудников и расчёт у менеджера. */
    public function payment(PaymentRecorded|PaymentClaimed $e): void
    {
        $invoice = $e instanceof PaymentClaimed ? $e->payment->invoice : $e->invoice;
        $this->publish->refresh(Topics::STAFF, ['/work/money', '/work/money/bank', '/work/deals', '/work/garage']);
        $garage = $invoice->deal_id ? null : GarageCar::ofInvoice($invoice);
        $manager = $invoice->deal?->buyer_id ?? $garage?->manager_id;
        if ($manager) {
            $this->publish->refresh(Topics::user($manager), ['/account/money', $garage ? '/garage/cars/'.$garage->offer->number : '/account/money/deals/'.$invoice->deal_id]);
        }
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
