<?php

namespace App\Mail;

use Illuminate\Support\Facades\DB;

/**
 * Побочный шаг письма — ровно один раз (26.11.2026): уведомление, строка истории, письмо поставщику. Письмо, разбор которого
 * убили посреди, дообрабатывается заново (`Ingest::handle`, дочитка `mail:sync`) — шаги, что уже прошли, не повторяются.
 * Отметка и шаг — одной транзакцией: очередь уведомлений в той же базе, задания встают вместе с отметкой или никак.
 */
final class MessageStep
{
    /** @return bool что вернул шаг (в первый раз) или запомненное (повтор) */
    public static function once(Message $message, string $step, callable $run): bool
    {
        return DB::transaction(function () use ($message, $step, $run) {
            $done = DB::table('mail_message_steps')->where('message_id', $message->id)->where('step', $step)->lockForUpdate()->first();
            if ($done) {
                return (bool) $done->result;
            }
            $result = $run();
            $ok = $result === null ? true : (bool) $result;
            DB::table('mail_message_steps')->insert(['message_id' => $message->id, 'step' => $step, 'result' => $ok, 'created_at' => now()]);

            return $ok;
        });
    }
}
