<?php

namespace App\Offers\Console;

use App\Notifications\InsurerReplyNotice;
use App\Telegram\Bot;
use App\Telegram\ChatMessage;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

/**
 * Уже отправленные «Страховая ответила» / «Контакты владельца» несли текст письма страховой — с закупочной, вендором и
 * подписью сотрудника (15.10.2026, владелец). В ленте текст стирается (остаётся «Ваш ход»), в Telegram сообщение
 * удаляется, а что Telegram удалить не даёт (старше 48 ч) — переписывается одним заголовком. Без `--apply` — только счёт.
 */
class ScrubReplyNoticesCommand extends Command
{
    protected $signature = 'deals:scrub-reply-notices {--apply : стереть, а не только посчитать}';

    protected $description = 'Убрать текст писем страховой из отправленных уведомлений и Telegram';

    public function handle(Bot $bot): int
    {
        $apply = (bool) $this->option('apply');

        $notices = DatabaseNotification::where('type', InsurerReplyNotice::class)->get();
        $dirty = $notices->filter(fn ($n) => filled($n->data['text'] ?? null) && ! preg_match('/^Ваш ход: [^\n]*$/u', $n->data['text']));
        $this->info("Лента: {$notices->count()}, с текстом письма {$dirty->count()}");
        if ($apply) {
            foreach ($dirty as $n) {
                preg_match('/^Ваш ход: .*$/mu', (string) $n->data['text'], $turn);
                $n->forceFill(['data' => [...$n->data, 'text' => $turn[0] ?? null]])->save();
            }
        }

        $sent = ChatMessage::where('direction', ChatMessage::OUT)->whereNull('deleted_at')->whereNotNull('message_id')
            ->where(fn ($q) => $q->where('text', 'like', '%Страховая ответила: %')->orWhere('text', 'like', '%Контакты владельца: %'))->get();
        $this->info("Telegram: {$sent->count()}");
        if (! $apply) {
            return self::SUCCESS;
        }
        [$deleted, $edited, $left] = [0, 0, 0];
        foreach ($sent as $message) {
            try {
                $bot->delete((int) $message->chat_id, (int) $message->message_id);
                $message->update(['deleted_at' => now()]);
                $deleted++;

                continue;
            } catch (Throwable) {
                // Старше 48 ч — Telegram не удаляет: переписываем.
            }
            $title = strtok(strip_tags((string) $message->text), "\n");
            if ($title && $bot->edit((int) $message->chat_id, (int) $message->message_id, '<b>'.e($title).'</b>')) {
                $message->update(['text' => $title, 'edited_at' => now()]);
                $edited++;
            } else {
                $left++;
            }
        }
        $this->info("Удалено {$deleted}, переписано {$edited}, не вышло {$left}");

        return self::SUCCESS;
    }
}
