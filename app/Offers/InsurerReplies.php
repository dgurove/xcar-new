<?php

namespace App\Offers;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Extraction\CloudShare;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\Patterns;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use Illuminate\Support\Collection;

/**
 * Ответы страховой по сделке (05.10.2026): письма вендора на ящик, который пишет (deal@), с начала сделки. offer@ только
 * принимает — там предложения и их пересылки, это не ответы. Менеджер видит все — текстом до подписи.
 */
final class InsurerReplies
{
    /** @return Collection<int, Message> новые сверху */
    public static function for(Deal $deal): Collection
    {
        $threads = Thread::where('offer_id', $deal->offer_id)
            ->whereIn('account_id', Account::where('scope', Scope::Offers)->whereNull('reply_account_id')->select('id'))->pluck('id');
        if ($threads->isEmpty()) {
            return collect();
        }

        return Message::whereIn('thread_id', $threads)->where('direction', Direction::In)->where('date_at', '>=', $deal->created_at)
            ->orderByDesc('date_at')->orderByDesc('id')->get()
            ->filter(fn (Message $m) => self::counts($m))->values();
    }

    /** Ответ страховой, а не автоответ и не наше. */
    public static function counts(Message $message): bool
    {
        return $message->isFromVendor() && $message->intent !== Intent::Auto->value && $message->replyText() !== '';
    }

    /** В ответе есть файлы: вложения или ссылка на облако (`CloudShare`). */
    public static function hasFiles(Message $message): bool
    {
        return $message->files()->isNotEmpty() || CloudShare::links($message->replyText(marks: true)) !== [];
    }

    /** В ответе есть, кому звонить: телефон в тексте до подписи. */
    public static function hasContact(Message $message): bool
    {
        return Patterns::phones($message->replyText()) !== [];
    }
}
