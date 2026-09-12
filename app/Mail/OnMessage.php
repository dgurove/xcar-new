<?php

namespace App\Mail;

use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Actions\LinkThread;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Jobs\ExtractCandidate;
use Illuminate\Events\Dispatcher;

/** Письмо разобрано: привязать к офферу, иначе — вычитать кандидата; и сказать админке. */
final class OnMessage
{
    public function __construct(private LinkThread $link, private Publisher $publish) {}

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
        $base = $message->account->scope === Scope::Park ? '/pochta' : '/perepiski/pochta';
        $this->publish->refresh($topic, [$base, "{$base}/{$message->thread_id}", '/nastroyki/kandidaty', '/kandidaty', '/zayavki']);
        if ($message->direction === Direction::In && ! $message->is_seen) {
            $this->publish->toast($topic, ($message->from_name ?: $message->from_email).': '.($message->subject ?: 'без темы'), "{$base}/{$message->thread_id}");
            $this->publish->badges($topic);
        }
    }

    public function sent(MessageSent $e): void
    {
        $this->publish->refresh(Topics::STAFF, ["/perepiski/pochta/{$e->message->thread_id}"]);
    }
}
