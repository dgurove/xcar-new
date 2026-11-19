<?php

namespace App\Offers\Console;

use App\Live\RefreshDeal;
use App\Offers\Listeners\MarkSupplierLetter;
use App\Offers\Offer;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Console\Command;

/**
 * Сделки на шаге с письмом поставщику, где шаг «не отправлено», а письмо этого шага уже ушло раньше (19.11.2026,
 * AUT-26-1109653: «Отдать» другому менеджеру стёрло отметку): засчитать тем же правилом, что при входе на этап
 * (`MarkSupplierLetter::already`). Без `--apply` — списком.
 */
class LettersRecheckCommand extends Command
{
    protected $signature = 'offers:letters-recheck {--apply}';

    protected $description = 'Засчитать шагам с письмом поставщику уже ушедшие письма';

    public function handle(): int
    {
        $n = 0;
        $positions = Position::where('track', Track::Sale->value)->with(['stage', 'offer.deal'])->get()
            ->filter(fn (Position $p) => $p->stage?->isLetterStep() && $p->offer?->deal?->isActive() && $p->letterStatus() === 'unsent');
        foreach ($positions as $position) {
            $offer = $position->offer;
            $sent = MarkSupplierLetter::already($offer, $position->stage);
            if (! $sent) {
                continue;
            }
            $n++;
            $at = $sent->sent_at ?? $sent->date_at ?? now();
            $this->line("№ {$offer->number} {$offer->title()}: «{$position->stage->name}», письмо {$at->format('d.m H:i')} «{$sent->subject}»");
            if ($this->option('apply')) {
                $position->update(['waits_for' => null, 'deadline_at' => $position->stage->deadlineFor($offer, $at->copy()), 'reminded_at' => null, 'overdue_at' => null,
                    'payload' => [...($position->payload ?? []), 'letter_id' => $sent->id, 'letter_at' => $at->toIso8601String()]]);
                RefreshDeal::of($offer->deal);
            }
        }
        $this->info(($this->option('apply') ? 'Засчитали: ' : 'Засчитаем: ').$n);

        return self::SUCCESS;
    }
}
