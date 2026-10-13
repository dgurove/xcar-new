<?php

namespace App\Chats\Jobs;

use App\Chats\AuthorKind;
use App\Chats\Message;
use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Sender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Ответ сотрудника гостю «Написать нам» — письмом (13.10.2026, владелец: гость видел ответ только в своём браузере). С ящика
 * продаж (offer@ отвечает с deal@, `Account::sender`), как `Notifications\MailboxChannel`: системный мейлер на проде
 * выключен. В переписку ящика письмо не ложится — переписка в чате. Ошибка транспорта — в лог, без повторов.
 */
final class SendGuestReply implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId) {}

    /** Кому слать: гостевое обращение с почтой, сообщение живого сотрудника (автоответ — нет). */
    public static function due(Message $message): bool
    {
        $chat = $message->chat;

        return $message->author_kind === AuthorKind::Staff && $message->author_id !== null && $message->deleted_at === null
            && $chat->isEnquiry() && $chat->user_id === null && filled($chat->guest_email) && filled($message->text);
    }

    public function handle(): void
    {
        $message = Message::with('chat')->find($this->messageId);
        $account = self::account();
        $email = $message && $account ? self::email($message, $account) : null;
        if (! $email) {
            return;
        }
        try {
            Sender::transport($account)->send($email);
        } catch (Throwable $e) {
            Log::warning('guest reply: '.$e->getMessage(), ['chat' => $message->chat_id, 'message' => $message->id]);
        }
    }

    /** Ящик продаж, с которого отвечаем: offer@ отвечает с deal@. */
    public static function account(): ?Account
    {
        return Account::where('scope', Scope::Offers)->where('is_active', true)->orderBy('id')->first()?->sender();
    }

    /** Письмо с ответом; null — слать нечего. */
    public static function email(Message $message, Account $account): ?Email
    {
        if (! self::due($message)) {
            return null;
        }
        $chat = $message->chat;
        $html = view('emails.guest-reply', [
            'name' => $chat->guest_name,
            'text' => $message->text,
            'url' => URL::temporarySignedRoute('contacts.open', now()->addDays(30), ['chat' => $chat->id]),
        ])->render();

        return (new Email)->from(new Address($account->email, $account->fromName()))
            ->to(new Address($chat->guest_email, (string) $chat->guest_name))
            ->subject('XCar: ответ на ваше обращение')->html($html)->text($message->text);
    }
}
