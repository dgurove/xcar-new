<?php

namespace App\Offers;

use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Media\ForManagers;
use App\Users\User;
use App\Support\Docs;
use Illuminate\Support\Collection;

/**
 * Письма и документы предложения — одно место на редактор, карточка строки и страницу сделки: карточка «Письма»
 * (все письма строками, число, ждущие ответа) и шторка документов (письмо вендора, документы, файлы писем, фото).
 */
final class OfferFiles
{
    /** Ветки писем предложения. */
    public static function threads(Offer $offer): Collection
    {
        return Thread::where('offer_id', $offer->id)->get();
    }

    /**
     * Карточка «Письма» (x-mail.letters): все письма по машине строками, как в «Почте», — те же ветки, что окно писем
     * (`Thread::ofCar`: ящики продаж, у того, кто ведёт парковку, ещё ветки её ТС), поэтому число в заголовке и окно
     * сходятся. asks — последнее входящее ветки, что ждёт ответа (одно правило почты — `needs_reply_at`), stages —
     * письма этапов цепочки «Из писем».
     *
     * @return array{messages: Collection<int, Message>, letters: int, asks: Collection, stages: Collection}
     */
    public static function letters(Offer $offer, ?User $user): array
    {
        $threads = Thread::ofCar($offer, $user)->get(['id', 'needs_reply_at']);
        $messages = $threads->isEmpty() ? collect() : Message::whereIn('thread_id', $threads->pluck('id'))
            ->with(['author', 'attachments', 'account'])->orderBy('date_at')->orderBy('id')->get();
        $waiting = $threads->whereNotNull('needs_reply_at')->pluck('id')->all();

        return [
            'messages' => $messages,
            'letters' => $messages->count(),
            'asks' => $messages->filter(fn (Message $m) => in_array($m->thread_id, $waiting, true) && ! $m->isOurs())->groupBy('thread_id')->map->last()->values(),
            'stages' => $messages->isEmpty() ? collect() : collect(Candidate::where('offer_id', $offer->id)->latest('id')->first(['id', 'stages'])?->stages ?? [])->pluck('message_id'),
        ];
    }

    public static function docs(Offer $offer, Collection $threads): array
    {
        // Входящие, а не «не наши»: сотрудник пересылает письмо вендора со своего ящика — это оно же, в цитате. Письма
        // ТС парковки сюда не идут (почта сторон не пересекается) — их файлы уже лежат у ТС, `papers()` их видит.
        $letters = Message::with(['attachments', 'account'])->whereIn('thread_id', $threads->pluck('id'))->where('direction', Direction::In)->orderBy('date_at')->get();
        $letter = $letters->first(fn (Message $m) => ! $m->isOurs()) ?? $letters->first();
        // «Вся переписка» у письма в шторке — окно-лента всех веток предложения на его же странице.
        $thread = $threads->isNotEmpty() ? "/offers/{$offer->number}?window=/offers/{$offer->number}/letters" : null;
        $papers = $offer->papers();
        $names = $papers->pluck('file_name')->map(fn ($n) => Docs::norm($n))->all();
        ['docs' => $files, 'photos' => $pictures] = Docs::fromLetters($letters, '/work/mail');
        $photos = $offer->photos();

        return array_values(array_filter([
            $letter ? Docs::letter($letter, '/work/mail', $thread) : null,
            ...$papers->map(fn ($m) => Docs::media($m))->all(),
            ...array_filter($files, fn ($d) => ! in_array(Docs::norm($d['name']), $names, true)),
            $photos->isNotEmpty() ? Docs::photos($photos) : Docs::photos($pictures, '/work/mail'),
        ]));
    }

    /** Документы ТС тому, кто с ней работает: сотруднику — все, менеджеру — отмеченные «Видно менеджеру». Писем нет. */
    public static function forManagers(Offer $offer, User $user): Collection
    {
        $papers = $offer->papers();

        return $user->isAdmin() ? $papers : $papers->filter(fn ($m) => ForManagers::is($m))->values();
    }
}
