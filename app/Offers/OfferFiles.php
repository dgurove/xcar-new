<?php

namespace App\Offers;

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Support\Docs;
use Illuminate\Support\Collection;

/**
 * Письма и документы предложения — одно место на редактор, окошко строки и страницу сделки: карточка «Письма»
 * (последнее письмо, число, ждущие ответа) и шторка документов (письмо вендора, документы, файлы писем, фото).
 */
final class OfferFiles
{
    /** Ветки писем предложения. */
    public static function threads(Offer $offer): Collection
    {
        return Thread::where('offer_id', $offer->id)->get();
    }

    /**
     * Карточка «Письма» (x-mail.last-letter), как в деле ТС: последнее письмо всех веток предложения, их число и письма,
     * что ждут ответа (одно правило почты — `needs_reply_at`, само письмо — последнее входящее ветки).
     *
     * @return array{lastLetter: ?Message, letters: int, asks: Collection}
     */
    public static function letters(Offer $offer, Collection $threads): array
    {
        $ids = $threads->pluck('id');
        $waiting = $threads->whereNotNull('needs_reply_at')->pluck('id');

        return [
            'lastLetter' => $ids->isEmpty() ? null : Message::whereIn('thread_id', $ids)->with(['author', 'attachments', 'account'])->orderByDesc('date_at')->first(),
            'letters' => $ids->isEmpty() ? 0 : Message::whereIn('thread_id', $ids)->count(),
            'asks' => $waiting->isEmpty() ? collect() : Message::whereIn('thread_id', $waiting)->where('direction', Direction::In)
                ->orderBy('date_at')->orderBy('id')->get(['id', 'thread_id', 'date_at'])->groupBy('thread_id')->map->last()->values(),
        ];
    }

    public static function docs(Offer $offer, Collection $threads): array
    {
        // Входящие, а не «не наши»: сотрудник пересылает письмо вендора со своего ящика — это оно же, в цитате.
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
}
