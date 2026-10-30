<?php

namespace App\Mail\Actions;

use App\Mail\Direction;
use App\Mail\Jobs\PushFlag;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Support\Nav;

/**
 * «Прочитано» у писем ветки — у нас и в самом ящике (`PushFlag`, `\Seen`). Это состояние человека, а не обработки
 * (30.10.2026, управляющий парковкой: «парсер помечает прочитанными письма, которые я ещё не открывал»). Звать — только когда
 * человек письма открыл (окно ветки, письма ТС или цепочки — не предзагрузкой), ответил, сам нажал «прочитано /
 * непрочитано» или когда дело закончено (наш отчёт о приёме, АПП выдачи — `OnMessage::onSent`). Разбор письма, заведение
 * заявки и ТС по письмам, привязка ветки к делу — не зовут.
 */
final class MarkThreadRead
{
    public function __construct(private Threads $threads) {}

    public function __invoke(Thread $thread, bool $read = true): void
    {
        $messages = $thread->messages()->where('direction', Direction::In)->where('is_seen', ! $read)->get();
        if ($messages->isEmpty()) {
            return;
        }
        foreach ($messages as $message) {
            $message->forceFill(['is_seen' => $read])->save();
            PushFlag::dispatch($message->id, '\\Seen', $read);
        }
        $this->threads->refresh($thread);
        Nav::forgetStaffCounts();
    }
}
