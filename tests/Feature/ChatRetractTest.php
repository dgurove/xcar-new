<?php

namespace Tests\Feature;

use App\Chats\Jobs\RetractChatMessage;
use App\Telegram\Chat as TelegramChat;
use App\Telegram\ChatMessage;
use App\Telegram\Retract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Удалённое сообщение чата стирается и в Telegram — надёжно (25.11.2026, владелец): сбой Telegram (502, сеть) не помечает сообщение
 * удалённым, задание повторится; «удалить нельзя» (старше 48 часов) — помечается без повтора.
 */
class ChatRetractTest extends TestCase
{
    use RefreshDatabase;

    private function sent(int $chatMessageId): array
    {
        config(['xcar.telegram.token' => 'test-token', 'xcar.mercure.hub' => '']);
        Sleep::fake();
        TelegramChat::forceCreate(['id' => 777]);
        $subject = RetractChatMessage::subjectOf($chatMessageId);

        return [
            ChatMessage::create(['chat_id' => 777, 'message_id' => 11, 'direction' => ChatMessage::OUT, 'kind' => 'text', 'text' => 'Привет', 'subject' => $subject]),
            ChatMessage::create(['chat_id' => 777, 'message_id' => 12, 'direction' => ChatMessage::OUT, 'kind' => 'document', 'subject' => $subject]),
        ];
    }

    public function test_deleted_in_telegram(): void
    {
        [$text, $file] = $this->sent(5);
        $calls = [];
        Http::fake(['api.telegram.org/*' => function ($r) use (&$calls) {
            $calls[] = $r->data()['message_id'] ?? null;

            return Http::response(['ok' => true, 'result' => true]);
        }]);
        (new RetractChatMessage(5, sweep: true))->handle(app(Retract::class));
        $this->assertEqualsCanonicalizing([11, 12], array_map('intval', $calls));
        $this->assertNotNull($text->fresh()->deleted_at);
        $this->assertNotNull($file->fresh()->deleted_at);
    }

    public function test_telegram_failure_retries_not_marked(): void
    {
        [$text] = $this->sent(6);
        $down = true;
        Http::fake(['api.telegram.org/*' => function () use (&$down) {
            return $down ? Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502) : Http::response(['ok' => true, 'result' => true]);
        }]);
        try {
            (new RetractChatMessage(6, sweep: true))->handle(app(Retract::class));
            $this->fail('сбой Telegram должен вернуть задание на повтор');
        } catch (RuntimeException) {
        }
        $this->assertNull($text->fresh()->deleted_at);

        // Повтор — Telegram ожил.
        $down = false;
        (new RetractChatMessage(6, sweep: true))->handle(app(Retract::class));
        $this->assertNotNull($text->fresh()->deleted_at);
    }

    public function test_cannot_delete_marked_without_retry(): void
    {
        [$text] = $this->sent(7);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => "Bad Request: message can't be deleted"], 400)]);
        (new RetractChatMessage(7, sweep: true))->handle(app(Retract::class));
        $this->assertNotNull($text->fresh()->deleted_at);
    }
}
