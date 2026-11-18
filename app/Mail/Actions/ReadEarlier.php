<?php

namespace App\Mail\Actions;

use App\Mail\Chains\NodeTitle;
use App\Mail\Direction;
use App\Mail\Message;

/**
 * Непрочитанным в ветке остаётся только последнее письмо (18.11.2026, владелец: «помечать прочитанными все предыдущие
 * сообщения в цепочке, непрочитанным оставлять только последнее и то если оно реально не прочитанное»). Зовёт `Ingest`,
 * когда письмо легло в ветку — входящее или наше (ответили — значит, письма до ответа видели):
 * - входящие раньше него по (`date_at`, id) — прочитаны;
 * - само оно прочитано, если в ветке уже есть письмо позже (из ящика догрузилось старое);
 * - его голова, если оно «ч.2» (`NodeTitle::continued`), не трогается: это одно письмо;
 * - позже него ничего не трогается — «Не прочитано» руками держится до следующего письма в ветку.
 * Флаг — тем же путём, что открытие письма (`MarkMessageRead::apply`): у нас и в ящике.
 */
final class ReadEarlier
{
    public function __construct(private MarkMessageRead $mark) {}

    /** @return list<int> письма, ставшие прочитанными */
    public function __invoke(Message $message): array
    {
        if (! $message->thread_id || ! $message->date_at) {
            return [];
        }
        $all = Message::where('thread_id', $message->thread_id)->whereNotNull('date_at')
            ->orderBy('date_at')->orderBy('id')->get(['id', 'thread_id', 'direction', 'is_seen', 'date_at', 'from_email', 'parsed', 'text_body', 'html_body']);
        $before = fn (Message $m) => $m->date_at->lt($message->date_at) || ($m->date_at->eq($message->date_at) && $m->id < $message->id);
        $later = $all->contains(fn (Message $m) => $m->id !== $message->id && ! $before($m));

        $incoming = $all->where('direction', Direction::In)->values();
        $continued = NodeTitle::continued($incoming);
        // Части одного письма: голова «ч.2» и все её части — вместе с самым поздним из них.
        $head = $continued[$message->id] ?? $message->id;
        $group = [$head, ...array_keys(array_filter($continued, fn ($h) => $h === $head))];

        $ids = $incoming->filter(fn (Message $m) => ! $m->is_seen && (
            ($m->id === $message->id && $later) || ($m->id !== $message->id && $before($m) && ($later || ! in_array($m->id, $group, true)))
        ))->pluck('id')->all();

        return $this->mark->apply(array_values($ids), true);
    }
}
