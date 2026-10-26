<?php

namespace App\Live;

use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentClaimed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentRejected;
use App\Billing\Events\PaymentVoided;
use App\Billing\Invoice;
use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessageChanged;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Events\ChatRead;
use App\Garage\Car as GarageCar;
use App\Garage\Events\GarageChanged;
use App\Logistics\Listeners\RefreshLogistics;
use App\Logistics\Transport;
use App\Notifications\ChatNotice;
use App\Notifications\Notice;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\CounterAnswered;
use App\Offers\Events\CounterOffered;
use App\Offers\Events\BidDeclined;
use App\Offers\Events\BidPlaced;
use App\Offers\Events\EstimateGiven;
use App\Offers\Events\EstimateRejected;
use App\Offers\Events\EstimatesAsked;
use App\Offers\Events\EstimatesClosed;
use App\Offers\Events\EstimateTaken;
use App\Offers\Events\InterestRegistered;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OffersHidden;
use App\Offers\Events\OffersShown;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\Showing;
use App\Support\Surface;
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
            CounterOffered::class => 'bidDecided',
            CounterAnswered::class => 'bidDecided',
            InterestRegistered::class => 'interest',
            EstimatesAsked::class => 'estimate',
            EstimateGiven::class => 'estimate',
            EstimateRejected::class => 'estimate',
            EstimateTaken::class => 'estimate',
            EstimatesClosed::class => 'estimate',
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
            PaymentRejected::class => 'payment',
            PaymentVoided::class => 'payment',
            InvoiceIssued::class => 'invoice',
            InvoiceVoided::class => 'invoice',
        ];
    }

    public function offer(OfferPublished|OfferStateChanged $e): void
    {
        $n = $e->offer->number;
        // Покупатели каталог не слушают — карточка и страница едут им в личные темы: продажа снимает её и у них.
        $buyers = Showing::seersOf($e->offer)->map(fn ($id) => Topics::user($id))->all();
        $this->publish->card($n, [Topics::CATALOG, ...$buyers]);
        $this->publish->refresh([Topics::CATALOG, ...$buyers], ["/offers/{$n}"]);
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        // Списки CRM — строкой: ушла из вкладки — уходит с экрана, во вкладку пришла — там перечитывается страница.
        $this->publish->rows(Topics::STAFF, ['/' => [$n], '/gallery' => [$n], '/work/deals' => RefreshCar::deals($e->offer)]);
        $this->logistics($e->offer);
    }

    /**
     * Продажа машины поменялась (вышла, сделка, гараж, снята, шаг сделки) — у «Вывоза» ведущих меняются столбец «Продажа»,
     * менеджер и срочность (18.10.2026): открытые задачи машины перечитываются хвостом.
     */
    private function logistics(Offer $offer): void
    {
        Transport::open()->where('car_id', $offer->car_id)->get()
            ->each(fn (Transport $t) => $this->publish->refreshSoon(RefreshLogistics::topics($t), ['/transports', "/transports/{$t->id}"]));
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
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        $this->publish->rows(Topics::STAFF, ['/' => [$n]]);
        $this->publish->refresh(Topics::CATALOG, ["/offers/{$n}"]);
    }

    public function bidDecided(BidAccepted|BidDeclined|CounterOffered|CounterAnswered $e): void
    {
        $n = $e->bid->offer->number;
        $this->publish->refresh(Topics::user($e->bid->user_id), ['/deals', "/offers/{$n}"]);
        // Отклонили последнее — машина возвращается из «Есть подтверждения» в «Опубликованные»: строка уходит, а там
        // перечитывается страница.
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        $this->publish->rows(Topics::STAFF, ['/' => [$n], '/work/deals' => RefreshCar::deals($e->bid->offer)]);
    }

    /**
     * Оценка менеджером: у менеджеров «Нужно оценить» в «Предложениях» и бейдж таба, у сотрудников — строка вкладки
     * «Без продажной цены» и число у пилюли.
     */
    public function estimate(EstimatesAsked|EstimateGiven|EstimateRejected|EstimateTaken|EstimatesClosed $e): void
    {
        [$offer, $users] = match (true) {
            $e instanceof EstimatesAsked => [$e->offer, $e->estimates->pluck('user_id')->all()],
            $e instanceof EstimatesClosed => [$e->offer, $e->userIds],
            default => [$e->estimate->offer, [$e->estimate->user_id]],
        };
        $n = $offer->number;
        foreach (array_unique($users) as $id) {
            $this->publish->refresh(Topics::user($id), ['/offers', "/offers/{$n}"]);
            $this->publish->badges(Topics::user($id));
        }
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        $this->publish->rows(Topics::STAFF, ['/' => [$n]]);
    }

    public function interest(InterestRegistered $e): void
    {
        $n = $e->interest->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        // «Галерея → Отклики» и число интересов в строке (18.10.2026: отклик живьём не появлялся).
        $this->publish->rows(Topics::STAFF, ['/gallery' => [$n], '/' => [$n]]);
        // Интерес покупателя — менеджеру: страница оффера и его список интересов.
        if ($manager = $e->interest->user->manager_id) {
            $this->publish->refresh(Topics::user($manager), ["/offers/{$n}", '/buyers/interest', "/buyers/{$e->interest->user_id}"]);
            $this->publish->badges(Topics::user($manager));
        }
    }

    public function stage(StageEntered $e): void
    {
        $n = $e->offer->number;
        $this->publish->refresh(Topics::STAFF, ["/offers/{$n}"]);
        $this->publish->rows(Topics::STAFF, ['/work/deals' => RefreshCar::deals($e->offer), '/work/garage' => [$n]]);
        // Шаг сделки двигает и «Продажу» вывоза: «после оплаты» / «ждём поставщика» снимаются здесь, а не сменой состояния.
        $this->logistics($e->offer);
        if (($deal = $e->deal ?? $e->offer->deal()->first()) && $deal->buyer_id) {
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
        $data = ['chat' => $chat->id, 'offer' => $chat->offer_id, 'seq' => $m->seq, 'author' => $m->author_id, 'text' => $m->preview(90)];
        $other = $this->otherSide($chat);
        $sides = [$other, $chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id)];
        // Кому какой адрес: участнику — его экран, второй стороне — свой; тост берёт по своей теме.
        // Карточка — готовой разметкой: кто написал (аватар автора), что, куда вести.
        $card = fn (string $from, string $href, ?Surface $surface = null) => ['from' => $from, 'href' => $href, 'banner' => Banner::chat($from, $m->preview(120), $href, $m->author_id ? $m->author : null, $chat->id, $surface)];
        ($this->publish)($sides[1], 'chat', $data + $card($chat->manager?->shortName() ?? Chat::PLATFORM, ChatNotice::hrefFor($chat, false)));
        // Карточка второй стороне — только когда пишет участник. Ответ сотрудника в чате площадки остальным сотрудникам —
        // одни данные для ленты, без карточки (06.10.2026, владелец: «уведомлять надо только если нам пишут»).
        ($this->publish)($other, 'chat', $m->author_kind === AuthorKind::Participant ? $data + $card($chat->displayName(), ChatNotice::hrefFor($chat, true), $chat->manager_id ? null : Surface::Crm) : $data);
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
        $n = $e->car->offer->number;
        $paths = ['/garage', "/garage/cars/{$n}"];
        $this->publish->refresh(Topics::STAFF, [...$paths, "/offers/{$n}"]);
        $this->publish->rows(Topics::STAFF, ['/work/garage' => [$n]]);
        $this->logistics($e->car->offer);
        if ($e->car->manager_id) {
            $this->publish->refresh(Topics::user($e->car->manager_id), $paths);
        }
    }

    /**
     * Оплата по счёту (по ссылке, из выписки, руками), заявка, отказ или отмена — перечитать деньги у сотрудников, расчёт
     * и сделку у менеджера, редактор в CRM (`RefreshDeal`: у гаражной сделки — машина в гараже, а не `/deals/{id}`).
     */
    public function payment(PaymentRecorded|PaymentClaimed|PaymentRejected|PaymentVoided $e): void
    {
        $this->invoiceChanged($e instanceof PaymentRecorded ? $e->invoice : $e->payment->invoice);
        $this->publish->refresh(Topics::STAFF, ['/settings/bank/statement']);
    }

    /** Счёт выставлен или аннулирован: у менеджера появляется «К оплате» и его ход, в CRM — строка и шаг сделки. */
    public function invoice(InvoiceIssued|InvoiceVoided $e): void
    {
        $this->invoiceChanged($e->invoice);
    }

    private function invoiceChanged(Invoice $invoice): void
    {
        $this->publish->refresh(Topics::STAFF, ['/work/money']);
        if ($invoice->deal) {
            RefreshDeal::of($invoice->deal);

            return;
        }
        if ($garage = GarageCar::ofInvoice($invoice)) {
            $n = $garage->offer->number;
            $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$n}"]);
            $this->publish->rows(Topics::STAFF, ['/work/garage' => [$n]]);
            if ($garage->manager_id) {
                $this->publish->refresh(Topics::user($garage->manager_id), ['/account/money', '/garage', "/garage/cars/{$n}"]);
            }
        }
    }

    public function notification(NotificationSent $e): void
    {
        if ($e->channel !== 'database' || ! $e->notifiable instanceof User) {
            return;
        }
        $topic = Topics::user($e->notifiable);
        // Сообщение чата карточкой показывает сам live-канал (событие chat) — второй раз не надо; тихое — только строка
        // в ленте и бейдж.
        if ($e->notification instanceof Notice && ! $e->notification instanceof ChatNotice && ! $e->notification->quiet()) {
            $this->publish->notice($topic, Banner::of($e->notification, (string) $e->notification->id, $e->notifiable));
        }
        $this->publish->badges($topic);
    }
}
