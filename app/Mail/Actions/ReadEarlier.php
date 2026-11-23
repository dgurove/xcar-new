<?php

namespace App\Mail\Actions;

use App\Mail\Account;
use App\Mail\Chains\NodeTitle;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use Illuminate\Database\Eloquent\Builder;

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

        $read = $this->mark->apply(array_values($ids), true);
        // Наше письмо (из почтовой программы — легло синхронизацией) — вся цепочка до него прочитана.
        if ($message->direction === Direction::Out) {
            $read = [...$read, ...$this->chain($message)];
        }

        return $read;
    }

    /**
     * Цепочка до прочитанного письма — прочитана (23.11.2026, владелец: «если последнее в цепочке прочитано — прочитаны и
     * все предыдущие», «мы написали поставщику — значит все предыдущие прочитаны»). Цепочка — дело «Почты» (`Boxes::group`:
     * предложение или ТС, цепочка «Из писем», иначе ветка) в ящиках той же стороны: письмо пришло на offer@, ответ ушёл с
     * deal@ — одна цепочка. Зовут, когда письмо открыли (`MarkMessageRead`, `MarkThreadRead`), прочитали в ящике (`Sync`) и
     * когда письмо наше (`OnMessage::onSent`, синхронизация). Новое входящее цепочку не читает — только свою ветку (18.11).
     *
     * @return list<int>
     */
    public function chain(Message $message): array
    {
        if (! $message->date_at || ! ($threads = self::chainThreads($message))) {
            return [];
        }
        $ids = Message::whereIn('thread_id', $threads)->where('direction', Direction::In)->where('is_seen', false)
            ->where('date_at', '<', $message->date_at)->pluck('id')->all();

        return $this->mark->apply($ids, true);
    }

    /** В цепочке позже письма есть прочитанное или наше — оно прочитано по `chain` (страж синхронизации флагов). */
    public static function settledInChain(Message $message): bool
    {
        return $message->date_at && ($threads = self::chainThreads($message)) && Message::whereIn('thread_id', $threads)
            ->where('id', '!=', $message->id)->where('date_at', '>', $message->date_at)
            ->where(fn ($q) => $q->where('is_seen', true)->orWhere('direction', Direction::Out))->exists();
    }

    /** Ветки цепочки письма — подзапросом; ветки нет — null. */
    private static function chainThreads(Message $message): ?Builder
    {
        $thread = $message->thread;
        $scope = $message->account?->scope;
        if (! $thread || ! $scope) {
            return null;
        }
        $own = $scope === Scope::Park ? 'vehicle_id' : 'offer_id';
        $query = Thread::whereIn('account_id', Account::where('scope', $scope)->select('id'))->select('id');

        return match (true) {
            (bool) $thread->{$own} => $query->where($own, $thread->{$own}),
            (bool) $thread->candidate_id => $query->whereNull($own)->where('candidate_id', $thread->candidate_id),
            default => $query->whereKey($thread->id),
        };
    }
}
