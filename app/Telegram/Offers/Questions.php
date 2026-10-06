<?php

namespace App\Telegram\Offers;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\Message;
use App\Offers\Offer;
use App\Support\Surface;
use App\Telegram\Bot;
use App\Telegram\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вопросы из бота — в обычный чат по предложению (владелец 03.10.2026): тот же, что на сайте и в CRM «Чаты».
 * Менеджер спрашивает 💬, админ, подписанный на бота, получает вопрос и отвечает реплаем — ответ ложится в чат от его
 * имени и приходит менеджеру реплаем на его вопрос; другим админам о нашем ответе не пишем (06.10.2026: уведомляем,
 * только когда пишут нам).
 * Связь «сообщение в Telegram → чат xcar» — `offer_bot_links`: по ней реплай находит свой чат.
 */
final class Questions
{
    public const ASK = 'ask';

    public const QUESTION = 'question';

    public const ANSWER = 'answer';

    public function __construct(private OffersBot $bot) {}

    /** 💬: вопрос менеджера → чат по предложению; его сообщение в Telegram запоминаем — ответ придёт реплаем на него. */
    public function ask(Subscriber $sub, Offer $offer, string $text, int $tgMessageId): void
    {
        $chat = app(OpenChat::class)($offer, $sub->user);
        // Связь — до сообщения: уведомление админам и ответ могут уйти раньше, чем мы сюда вернёмся.
        self::link($sub->chat_id, $tgMessageId, $chat->id, null, self::ASK);
        $message = app(PostMessage::class)($chat, $sub->user, $text, viaBot: true);
        DB::connection('pgsql_async')->table('offer_bot_links')->where('tg_chat_id', $sub->chat_id)->where('tg_message_id', $tgMessageId)->update(['seq' => $message->seq]);
    }

    /**
     * Реплай в боте на вопрос или ответ по предложению: админ отвечает в чат, менеджер дописывает туда же. false — это
     * не реплай на наше сообщение (обычный разговор).
     */
    public function reply(Subscriber $sub, int $replyTo, array $msg): bool
    {
        $link = DB::connection('pgsql_async')->table('offer_bot_links')->where('tg_chat_id', $sub->chat_id)->where('tg_message_id', $replyTo)->first();
        $chat = $link ? Chat::with('offer')->find($link->chat_id) : null;
        if (! $chat) {
            return false;
        }
        $user = $sub->user;
        $own = $chat->user_id === $user->id;
        if (! $own && ! $chat->isCounterpart($user)) {
            return false;
        }
        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        if ($text === '') {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Напишите ответ текстом', null, self::replyTo((int) $msg['message_id'])));

            return true;
        }
        self::link($sub->chat_id, (int) $msg['message_id'], $chat->id, null, $own ? self::ASK : self::ANSWER);
        $message = app(PostMessage::class)($chat, $user, $text, replyTo: $link->seq ? (int) $link->seq : null, viaBot: $own);
        DB::connection('pgsql_async')->table('offer_bot_links')->where('tg_chat_id', $sub->chat_id)->where('tg_message_id', (int) $msg['message_id'])->update(['seq' => $message->seq]);
        app(MarkChatRead::class)($chat->refresh(), $user);
        $this->bot->quietly(fn () => $this->bot->react($sub->chat_id, (int) $msg['message_id'], '👌'));

        return true;
    }

    /** Сообщение чата в Telegram через бот: админу — вопрос, менеджеру — ответ реплаем на его вопрос. */
    public function deliver(Subscriber $sub, Message $message, bool $forStaff): void
    {
        $chat = $message->chat;
        $offer = $chat->offer;
        $text = e((string) $message->preview(3000));
        $extra = $sub->user->quietHours() ? ['disable_notification' => 'true'] : [];
        try {
            if ($forStaff) {
                $lines = Text::lines($offer, e($chat->displayName()).': '.$text);
                $id = $this->bot->say($sub->chat_id, '<b>Вопрос по '.e($offer?->titleWithYear() ?? 'предложению').'</b>'."\n".implode("\n", $lines),
                    Keys::inline([[['text' => 'Открыть чат', 'url' => Surface::Crm->url('/work/chats/'.$chat->id)]]]), $extra);
                self::link($sub->chat_id, $id, $chat->id, $message->seq, self::QUESTION);
            } else {
                $ask = DB::connection('pgsql_async')->table('offer_bot_links')->where('tg_chat_id', $sub->chat_id)->where('chat_id', $chat->id)
                    ->where('kind', self::ASK)->orderByDesc('created_at')->value('tg_message_id');
                $id = $this->bot->say($sub->chat_id, '<b>Ответ по '.e($offer?->titleWithYear() ?? 'предложению').'</b>'."\n".$text, null,
                    $extra + ($ask ? self::replyTo((int) $ask) : []));
                self::link($sub->chat_id, $id, $chat->id, $message->seq, self::ANSWER);
            }
        } catch (Throwable $e) {
            if (Bot::chatGone($e)) {
                $sub->forceFill(['blocked_at' => now()])->save();
            }
            Log::warning('Бот предложений: сообщение чата не ушло', ['chat' => $chat->id, 'error' => $e->getMessage()]);
        }
    }

    private static function link(int $tgChat, int $tgMessage, int $chatId, ?int $seq, string $kind): void
    {
        if (! $tgMessage) {
            return;
        }
        DB::connection('pgsql_async')->table('offer_bot_links')->insertOrIgnore([
            'tg_chat_id' => $tgChat, 'tg_message_id' => $tgMessage, 'chat_id' => $chatId, 'seq' => $seq, 'kind' => $kind, 'created_at' => now(),
        ]);
    }

    private static function replyTo(int $messageId): array
    {
        return ['reply_parameters' => json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true])];
    }
}
