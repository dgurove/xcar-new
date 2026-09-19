<?php

namespace App\Mail;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\LinkThread;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Jobs\ExtractCandidate;
use App\Park\Actions\MarkSold;
use App\Park\Events\LetterArrived;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Events\Dispatcher;

/** Письмо разобрано: привязать к офферу, иначе — вычитать кандидата; и сказать админке. */
final class OnMessage
{
    public function __construct(private LinkThread $link, private Publisher $publish, private MarkSold $sold) {}

    public function subscribe(Dispatcher $events): array
    {
        return [MessageParsed::class => 'parsed', MessageSent::class => 'sent'];
    }

    public function parsed(MessageParsed $e): void
    {
        $message = $e->message;
        $linked = $this->link->auto($message);
        $thread = $message->thread;
        // Ветка уже привязана — это переписка по машине, а не новая: кандидатов из неё не делаем.
        if (! $linked && ! $thread?->offer_id && ! $thread?->vehicle_id && $message->direction === Direction::In) {
            ExtractCandidate::dispatch($message->id);
        }
        $topic = $message->account->scope === Scope::Park ? Topics::PARK : Topics::STAFF;
        $base = $message->account->scope === Scope::Park ? '/mail' : '/work/mail';
        $paths = [$base, "{$base}/{$message->thread_id}", '/offers/from-mail', '/requests/from-mail', '/'];
        // Письмо по привязанной ТС — в её ленту и сотруднику, который ею занят.
        $vehicle = $linked instanceof Vehicle ? $linked : $thread?->vehicle;
        if ($vehicle && $message->direction === Direction::In) {
            $vehicle->log(EventType::Letter, null, ['from' => $message->from_name ?: $message->from_email, 'subject' => $message->subject, 'thread' => $message->thread_id]);
            // «Продано, заберёт такой-то» по ТС на стоянке — дата продажи и покупатель из письма, заявка на выдачу.
            if ($vehicle->state === VehicleState::Stored && ! $vehicle->sold_at && ($sold = ParkExtractor::soldNotice($message->subject, $message->text_body ?: strip_tags((string) $message->html_body)))) {
                ($this->sold)($vehicle, null, $message->date_at ?? now(), $sold['name'], $sold['phone'], $sold['note'], $message);
            } else {
                LetterArrived::dispatch($vehicle, $message);
            }
            $paths[] = "/cars/{$vehicle->id}";
        }
        $this->publish->refresh($topic, $paths);
        if ($message->direction === Direction::In && ! $message->is_seen) {
            $this->publish->toast($topic, ($message->from_name ?: $message->from_email).': '.($message->subject ?: 'без темы'), "{$base}/{$message->thread_id}");
            $this->publish->badges($topic);
        }
    }

    public function sent(MessageSent $e): void
    {
        $park = $e->message->account->scope === Scope::Park;
        $this->publish->refresh($park ? Topics::PARK : Topics::STAFF, [($park ? '/mail/' : '/work/mail/').$e->message->thread_id]);
    }
}
