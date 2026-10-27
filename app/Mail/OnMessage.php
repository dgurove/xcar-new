<?php

namespace App\Mail;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Extraction\Intent;
use App\Offers\Listeners\AdvanceOnReply;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Reading\ReadLetter;
use App\Notifications\Notify;
use App\Notifications\ParkNotice;
use App\Offers\Actions\FillFromLetter;
use App\Offers\Actions\ShowInGallery;
use App\Offers\Events\InsurerReplied;
use App\Offers\InsurerReplies;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Actions\MarkSold;
use App\Park\Actions\ReleaseByLetters;
use App\Park\Actions\ReportByLetters;
use App\Park\Actions\StoreByLetters;
use App\Park\City;
use App\Park\Events\LetterArrived;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\VendorLetter;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Письмо разобрано: привязать к офферу, иначе — вычитать кандидата; и сказать админке. */
final class OnMessage
{
    public function __construct(private LinkThread $link, private Publisher $publish, private MarkSold $sold, private ChainBuilder $chains, private ReadLetter $reader, private StoreByLetters $store) {}

    public function subscribe(Dispatcher $events): array
    {
        return [MessageParsed::class => 'parsed', MessageSent::class => 'sent'];
    }

    /** Разбор письма и его следствия — служебная работа: в запросе управляющего с городом без областей (`City::unscoped`). */
    public function parsed(MessageParsed $e): void
    {
        City::unscoped(fn () => $this->onParsed($e));
    }

    private function onParsed(MessageParsed $e): void
    {
        $message = $e->message;
        if (! $message->isRead()) {
            $this->reader->apply($message);
        }
        $linked = $this->link->auto($message);
        $thread = $message->thread;
        // Ветка уже привязана — это переписка по машине, а не новая: в цепочки «Из писем» не идёт.
        // Иначе письмо (и наше тоже: ответ с актом двигает этап) — в цепочку по номерам и ветке.
        if (! $linked && ! $thread?->offer_id && ! $thread?->vehicle_id && ($candidate = $this->chains->attach($message, quiet: $e->quiet))) {
            // Мы уже писали вендору, что приняли: ТС заводится сама, цепочка уходит из «Из писем». Заявку на приём или
            // эвакуацию по новой цепочке завела сама цепочка (`ChainBuilder::attach` → `AutoRequest`).
            ($this->store)($candidate);
        }
        if ($e->quiet) {
            return; // история ящика: в базу легло, людей не дёргаем
        }
        // Письмо по заведённому предложению дописывает пустое: контакт, у черновика — машину, цену, документы.
        if ($offer = $linked instanceof Offer ? $linked : $thread?->offer) {
            // Новое письмо вендора по черновику в галерее — окно закрывается (привязку новой ветки ловит LinkThread).
            ShowInGallery::onLetter($offer);
            try {
                app(FillFromLetter::class)($offer, $message);
            } catch (Throwable $e) {
                Log::warning('fill-from-letter: '.$e->getMessage(), ['offer' => $offer->id, 'message' => $message->id]);
            }
            // Ответ страховой по идущей сделке на ящик, который пишет (deal@): менеджеру и шагу сделки.
            $deal = $offer->deal;
            if ($deal && $message->account->scope === Scope::Offers && $message->account->reply_account_id === null
                && $message->date_at?->gte($deal->created_at) && InsurerReplies::counts($message)
                && ! $offer->events()->where('type', OfferEventType::InsurerReplied)->where('payload->letter', $message->id)->exists()) {
                InsurerReplied::dispatch($deal, $message);
            } elseif ($deal && $message->account->scope === Scope::Offers && $message->direction === Direction::In
                && ($message->isFromVendor() || $message->isForwardedByStaff()) && ! Intent::silent($message->intent)) {
                AdvanceOnReply::mark($deal, $message);
            }
        }
        $this->toTelegram($message, $linked instanceof Offer ? $linked : $thread?->offer);
        $topic = $message->account->scope === Scope::Park ? Topics::PARK : Topics::STAFF;
        $base = $message->account->scope === Scope::Park ? '/mail' : '/work/mail';
        // «Заявки» — тоже: заявку из письма могла только что завести почта (`AutoRequest`), её строка входит силуэтом.
        $paths = [$base, "{$base}/{$message->thread_id}", '/offers/from-mail', '/requests/from-mail', '/requests', '/'];
        // Письмо по привязанной ТС — в её ленту и сотруднику, который ею занят.
        $vehicle = $linked instanceof Vehicle ? $linked : $thread?->vehicle;
        // Наше письмо о выдаче, отправленное не из приложения (ящик в почтовой программе), — так же выдаёт.
        if ($vehicle && $message->account->scope === Scope::Park && $message->intent === Intent::Released->value && $message->isOurs()) {
            app(ReleaseByLetters::class)($vehicle);
        }
        // Наш отчёт о приёме из почтовой программы — шаг «Отчёт вендору» сделан.
        if ($vehicle && $message->account->scope === Scope::Park && $message->intent === Intent::Accepted->value && $message->isOurs()) {
            app(ReportByLetters::class)($vehicle);
        }
        if ($vehicle && $message->direction === Direction::In) {
            $vehicle->log(EventType::Letter, null, ['from' => $message->from_name ?: $message->from_email, 'subject' => $message->subject, 'thread' => $message->thread_id, 'message' => $message->id, 'intent' => $message->intent]);
            // «Продано, заберёт такой-то» по ТС на стоянке — дата продажи и покупатель из письма, заявка на выдачу.
            if ($vehicle->state === VehicleState::Stored && ! $vehicle->sold_at && $message->intent === Intent::Sold->value
                && ($sold = ParkExtractor::soldNotice($message->subject, $message->ownText()))) {
                ($this->sold)($vehicle, null, $message->date_at ?? now(), $sold['name'], $sold['phone'], $message, $sold['free_until']);
            } elseif (! Intent::silent($message->intent)) {
                LetterArrived::dispatch($vehicle, $message);
                // Страховая продала машину, по которой у нас живой пропуск сделки (18.10.2026): продажу сделки письмо не
                // перетирает — но людям решать, кому отдавать: парковке громко (управляющим и администраторам), в CRM — строкой
                // в истории предложения.
                if ($message->intent === Intent::Sold->value && ($pass = $vehicle->dealPass())) {
                    Notification::send(
                        app(Notify::class)->parkManagersOf($vehicle->city_id)
                            ->merge(User::withRole(Role::ParkAdmin)->whereNotNull('approved_at')->get())->unique('id'),
                        ParkNotice::insurerSoldDeal($vehicle, $pass));
                    $vehicle->offer?->log(OfferEventType::Note, null, ['text' => 'Страховая прислала «продано» по ТС, а по нашей сделке ждём '.$pass->name.': сверить, кому выдавать']);
                }
            }
            $paths[] = "/cars/{$vehicle->id}";
        }
        $this->publish->refresh($topic, $paths);
        if ($message->direction === Direction::In && ! $message->is_seen) {
            $this->publish->toast($topic, ($message->from_name ?: $message->from_email).': '.($message->subject ?: 'без темы'), "{$base}/{$message->thread_id}");
            $this->publish->badges($topic);
        }
    }

    /**
     * Письмо вендора с галкой `letters_to_telegram` (АльфаСтрахование, 17.10.2026) на ящик, который пишет (deal@), —
     * владельцу и админам в Telegram. Вендор — ветки, иначе разбора письма (пересылка сотрудника — по цитате).
     */
    private function toTelegram(Message $message, ?Offer $offer): void
    {
        if (! $message->isFromVendor() || $message->account->scope !== Scope::Offers
            || $message->account->reply_account_id !== null || in_array($message->intent, [Intent::Auto->value, Intent::Bounce->value], true)) {
            return;
        }
        $vendor = $message->thread?->vendor ?? Vendor::find($message->field('vendor_id'));
        if ($vendor?->letters_to_telegram) {
            NotifyOwner::dispatch(new VendorLetter($message, $vendor, $offer));
        }
    }

    public function sent(MessageSent $e): void
    {
        City::unscoped(fn () => $this->onSent($e));
    }

    private function onSent(MessageSent $e): void
    {
        $park = $e->message->account->scope === Scope::Park;
        // Наше письмо из приложения: прочитать и, если ветка не за ТС, положить в цепочку (этап «принята» / «выдана»).
        $message = $e->message->fresh(['account', 'thread', 'attachments']);
        $this->reader->apply($message);
        // Ответили — ветка обработана: входящие прочитаны.
        if ($message->thread) {
            app(MarkThreadRead::class)($message->thread);
        }
        if ($park && ! $message->thread?->vehicle_id && ! $message->thread?->offer_id && ($candidate = $this->chains->attach($message, quiet: true))) {
            // Это письмо и есть «приняли, отчёт отправлен» — дальше ТС заводится сама.
            ($this->store)($candidate);
        }
        // Наше «подписанный АПП» по стоящей ТС — выдана с этого дня.
        if ($park && $message->thread?->vehicle && $message->intent === Intent::Released->value) {
            app(ReleaseByLetters::class)($message->thread->vehicle);
        }
        // Наше «принята» не окном шага (ответ в ветке) — отчёт о приёме тоже сделан.
        if ($park && $message->thread?->vehicle && $message->intent === Intent::Accepted->value) {
            app(ReportByLetters::class)($message->thread->vehicle);
        }
        $this->publish->refresh($park ? Topics::PARK : Topics::STAFF, [($park ? '/mail/' : '/work/mail/').$e->message->thread_id]);
    }
}
