<?php

namespace App\Mail\Extraction;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Support\Nav;
use Illuminate\Support\Collection;

/**
 * Этапы цепочки писем кандидата: по всем письмам его веток (входящие и наши, по дате) видно, где ТС на самом деле.
 * «Заявка» — первое письмо о приёме; «Принята» — наш ответ с актом («по принятому ТС») или письмо вендора о том,
 * что принято, об осмотре, о бумагах; «Продана» — письмо «реализовано, покупатель заберёт»; «Выдана» — наш
 * «подписанный АПП» с актом выдачи или «вывез» от вендора. Наши — и с личных ящиков сотрудников (`Message::isOurs`).
 * Список ложится в `mail_candidates.stages`, последний — в `stage`; форма заведения показывает этапы менеджеру
 * на проверку. Выданная цепочка закрыта — кандидат уходит в архив вместе с ветками.
 */
final class CandidateStages
{
    public function __construct(private ArchiveThread $archive) {}

    /** @return list<array{stage: string, at: ?string, message_id: int, title: string, name?: ?string, phone?: ?string, note?: ?string}> */
    public function of(Candidate $candidate): array
    {
        $messages = $this->messages($candidate);
        $stages = [];
        // Заявка — письмо вендора или пересланная своим человеком (смысл intake у неё уже от пересланного).
        $intake = $messages->first(fn (Message $m) => ($m->intent ?? 'other') === Intent::Intake->value && $m->direction !== Direction::Out)
            ?? $messages->first(fn (Message $m) => ! $m->isOurs());
        if ($intake) {
            $stages[] = ['stage' => CandidateStage::Intake->value, 'at' => $intake->date_at?->toDateTimeString(), 'message_id' => $intake->id, 'title' => 'Заявка на приём'];
        }
        $accepted = $messages->first(fn (Message $m) => $m->isOurs() && $m->intent === Intent::Accepted->value)
            ?? $messages->first(fn (Message $m) => ! $m->isOurs() && in_array($m->intent, [Intent::Accepted->value, Intent::Inspect->value, Intent::Docs->value, Intent::CancelRelease->value, Intent::Hold->value], true));
        $sold = $messages->last(fn (Message $m) => ! $m->isOurs() && $m->intent === Intent::Sold->value);
        $released = $messages->last(fn (Message $m) => $m->intent === Intent::Released->value);
        if ($accepted || $sold || $released) {
            $at = $accepted?->date_at ?? $sold?->date_at ?? $released?->date_at;
            $stages[] = ['stage' => CandidateStage::Stored->value, 'at' => $at?->toDateTimeString(), 'message_id' => ($accepted ?? $sold ?? $released)->id,
                'title' => $accepted?->isOurs() ? 'Принята, отчёт отправлен' : 'Принята'];
        }
        if ($sold) {
            $notice = ParkExtractor::soldNotice($sold->subject, $sold->text_body ?: strip_tags((string) $sold->html_body)) ?? [];
            $stages[] = ['stage' => CandidateStage::Sold->value, 'at' => $sold->date_at?->toDateTimeString(), 'message_id' => $sold->id, 'title' => 'Продана',
                'name' => $notice['name'] ?? null, 'phone' => $notice['phone'] ?? null, 'note' => $notice['note'] ?? null];
        }
        // Выдана — только если после этого вендор не писал: письмо после выдачи ждёт человека, а не архив.
        if ($released && ! $messages->contains(fn (Message $m) => ! $m->isOurs() && $m->date_at > $released->date_at && $m->intent !== Intent::Other->value)) {
            $stages[] = ['stage' => CandidateStage::Released->value, 'at' => $released->date_at?->toDateTimeString(), 'message_id' => $released->id, 'title' => 'Выдана'];
        }

        return $stages;
    }

    public function refresh(Candidate $candidate): void
    {
        $stages = $this->of($candidate);
        $last = $stages ? end($stages)['stage'] : CandidateStage::Intake->value;
        $candidate->forceFill(['stages' => $stages, 'stage' => $last])->saveQuietly();
        // Выдана — заводить нечего: цепочка в архив, ветки с ней.
        if ($last === CandidateStage::Released->value && ($candidate->state ?? CandidateState::New) === CandidateState::New) {
            $candidate->forceFill(['state' => CandidateState::Rejected])->saveQuietly();
            $this->archive->candidate($candidate);
            Nav::forgetStaffCounts();
        }
    }

    /** Все письма цепочки по дате: письма кандидата и все письма его веток, у писем без смысла он считается тут же. */
    private function messages(Candidate $candidate): Collection
    {
        $threadIds = $candidate->messages()->pluck('thread_id')->filter()
            ->merge(Thread::where('candidate_id', $candidate->id)->pluck('id'))->unique()->all();
        $messages = Message::with(['attachments', 'account'])->where(fn ($q) => $q->whereIn('thread_id', $threadIds)->orWhereIn('id', $candidate->messages()->pluck('mail_messages.id')))
            ->orderBy('date_at')->get();
        foreach ($messages as $m) {
            if ($m->intent === null) {
                $m->forceFill(['intent' => Intent::ofMessage($m)->value])->saveQuietly();
            }
        }

        // Бухгалтерия (отчёт-акт со списком машин) и автоответы — не про эту ТС, этапов не дают.
        return $messages->reject(fn (Message $m) => in_array($m->intent, [Intent::Billing->value, Intent::Auto->value], true))->values();
    }
}
