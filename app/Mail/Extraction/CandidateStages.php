<?php

namespace App\Mail\Extraction;

use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\Direction;
use App\Mail\Message;
use Illuminate\Support\Collection;

/**
 * Этапы цепочки писем кандидата: по всем письмам его веток (входящие и наши, по дате) видно, где ТС на самом деле.
 * «Заявка» — первое письмо о приёме; «Принята» — наш ответ с актом («по принятому ТС») или письмо вендора о том,
 * что принято, об осмотре, о бумагах; «Продана» — письмо «реализовано, покупатель заберёт». Список ложится в
 * `mail_candidates.stages`, последний — в `stage`; форма заведения показывает этапы менеджеру на проверку.
 */
final class CandidateStages
{
    /** @return list<array{stage: string, at: ?string, message_id: int, title: string, name?: ?string, phone?: ?string, note?: ?string}> */
    public function of(Candidate $candidate): array
    {
        $messages = $this->messages($candidate);
        $stages = [];
        $intake = $messages->first(fn (Message $m) => $m->direction === Direction::In && Intent::from($m->intent ?? 'other') === Intent::Intake)
            ?? $messages->first(fn (Message $m) => $m->direction === Direction::In);
        if ($intake) {
            $stages[] = ['stage' => CandidateStage::Intake->value, 'at' => $intake->date_at?->toDateTimeString(), 'message_id' => $intake->id, 'title' => 'Заявка на приём'];
        }
        $accepted = $messages->first(fn (Message $m) => $m->direction === Direction::Out && $m->intent === Intent::Accepted->value)
            ?? $messages->first(fn (Message $m) => $m->direction === Direction::In && in_array($m->intent, [Intent::Accepted->value, Intent::Inspect->value, Intent::Docs->value, Intent::CancelRelease->value], true));
        $sold = $messages->last(fn (Message $m) => $m->direction === Direction::In && $m->intent === Intent::Sold->value);
        if ($accepted || $sold) {
            $at = $accepted?->date_at ?? $sold?->date_at;
            $stages[] = ['stage' => CandidateStage::Stored->value, 'at' => $at?->toDateTimeString(), 'message_id' => ($accepted ?? $sold)->id,
                'title' => $accepted?->direction === Direction::Out ? 'Принята, отчёт отправлен' : 'Принята'];
        }
        if ($sold) {
            $notice = ParkExtractor::soldNotice($sold->subject, $sold->text_body ?: strip_tags((string) $sold->html_body)) ?? [];
            $stages[] = ['stage' => CandidateStage::Sold->value, 'at' => $sold->date_at?->toDateTimeString(), 'message_id' => $sold->id, 'title' => 'Продана',
                'name' => $notice['name'] ?? null, 'phone' => $notice['phone'] ?? null, 'note' => $notice['note'] ?? null];
        }

        return $stages;
    }

    public function refresh(Candidate $candidate): void
    {
        $stages = $this->of($candidate);
        $last = $stages ? end($stages)['stage'] : CandidateStage::Intake->value;
        $candidate->forceFill(['stages' => $stages, 'stage' => $last])->saveQuietly();
    }

    /** Все письма цепочки по дате: письма кандидата и все письма его веток, у писем без смысла он считается тут же. */
    private function messages(Candidate $candidate): Collection
    {
        $threadIds = $candidate->messages()->pluck('thread_id')->filter()->unique()->all();
        $messages = Message::with(['attachments'])->where(fn ($q) => $q->whereIn('thread_id', $threadIds)->orWhereIn('id', $candidate->messages()->pluck('mail_messages.id')))
            ->orderBy('date_at')->get();
        foreach ($messages as $m) {
            if ($m->intent === null) {
                $m->forceFill(['intent' => Intent::ofMessage($m)->value])->saveQuietly();
            }
        }

        return $messages;
    }
}
