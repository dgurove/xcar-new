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
        $offer = $this->link->auto($message);
        if (! $offer && $message->direction === Direction::In && $message->account->scope === Scope::Offers) {
            ExtractCandidate::dispatch($message->id);
        }
        $topic = $message->account->scope === Scope::Park ? Topics::PARK : Topics::STAFF;
        $this->publish->refresh($topic, ['/admin/pochta', "/admin/pochta/{$message->thread_id}", '/admin/kandidaty', '/pochta', "/pochta/{$message->thread_id}"]);
        if ($message->direction === Direction::In && ! $message->is_seen) {
            $this->publish->toast($topic, ($message->from_name ?: $message->from_email).': '.($message->subject ?: 'без темы'), "/admin/pochta/{$message->thread_id}");
            $this->publish->badges($topic);
        }
    }

    public function sent(MessageSent $e): void
    {
        $this->publish->refresh(Topics::STAFF, ["/admin/pochta/{$e->message->thread_id}"]);
    }
}
