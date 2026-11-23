<?php

namespace App\Mail\Actions;

use App\Mail\Chains\NodeTitle;
use App\Mail\Direction;
use App\Mail\Jobs\PushSeen;
use App\Mail\Message;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Support\Nav;

/**
 * «Прочитано» по письму — «Входящие» почты (17.11.2026): письмо открыли справа — прочитаны оно, его «ч.2» и всё, что
 * пришло в ветку раньше (переписку над ним видели, как в Gmail); «Не прочитано» — одно письмо. У нас и в ящике (`PushSeen`).
 * Ветку целиком — по-прежнему `MarkThreadRead` (окно писем дела).
 */
final class MarkMessageRead
{
    public function __construct(private Threads $threads) {}

    /** @return list<int> письма, которым сменили флаг */
    public function __invoke(Message $message, bool $read = true): array
    {
        $letters = Message::where('thread_id', $message->thread_id)->where('direction', Direction::In)->orderBy('date_at')->orderBy('id')->get();
        $parts = array_keys(array_filter(NodeTitle::continued($letters), fn ($head) => $head === $message->id));
        $ids = $letters->filter(fn (Message $m) => $m->is_seen !== $read && (in_array($m->id, [$message->id, ...$parts], true)
            || ($read && $m->date_at && $message->date_at && $m->date_at->lt($message->date_at))))->pluck('id')->all();

        $changed = $this->apply($ids, $read);
        // Открыли — и всё раньше него во всей цепочке (другие ветки и ящики машины) прочитано (`ReadEarlier::chain`).
        if ($read) {
            $changed = [...$changed, ...app(ReadEarlier::class)->chain($message)];
        }

        return $changed;
    }

    /** Флаг письмам — у нас, в ящике (`PushSeen`), счётчики веток и «Почты»; и `ReadEarlier`. @param list<int> $ids @return list<int> */
    public function apply(array $ids, bool $read): array
    {
        if (! $ids) {
            return [];
        }
        $threads = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $threads = [...$threads, ...Message::whereIn('id', $chunk)->distinct()->pluck('thread_id')->all()];
            Message::whereIn('id', $chunk)->update(['is_seen' => $read]);
            PushSeen::dispatch($chunk, $read);
        }
        foreach (Thread::whereIn('id', array_unique(array_filter($threads)))->get() as $thread) {
            $this->threads->refresh($thread);
        }
        Nav::forgetStaffCounts();

        return $ids;
    }
}
