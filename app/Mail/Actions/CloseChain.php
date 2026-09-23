<?php

namespace App\Mail\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Message;
use App\Mail\Threads;
use App\Support\Nav;

/**
 * Цепочка «Из писем» закрыта навсегда: ТС выдана по письмам, реализована без нас или цепочка ушла в архив по давности.
 * Заголовок и этапы остаются (архив читается), карточка-кадр снимается, ветки в архив, письма замораживаются
 * (`FreezeMessages`). Новое письмо о той же машине закрытую не оживляет — это новый заезд, новая цепочка.
 */
final class CloseChain
{
    public function __construct(private ArchiveThread $archive, private FreezeMessages $freeze) {}

    public function __invoke(Candidate $candidate, ?string $reason = null): void
    {
        if ($candidate->state === CandidateState::Promoted) {
            return;
        }
        $candidate->forceFill(['state' => CandidateState::Closed, 'closed_at' => now(), 'proposed' => null])->saveQuietly();
        $this->archive->candidate($candidate);
        // Закрыта — обработана: непрочитанных не остаётся (без флага в ящик, письма старые).
        Message::whereIn('id', $candidate->messages()->pluck('mail_messages.id'))->where('is_seen', false)->update(['is_seen' => true]);
        foreach ($candidate->threads() as $thread) {
            app(Threads::class)->refresh($thread);
        }
        $this->freeze->freeze($candidate->messages()->pluck('mail_messages.id')->all());
        Nav::forgetStaffCounts();
    }
}
