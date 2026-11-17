<?php

namespace App\Offers\Console;

use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\ReviveClosed;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Console\Command;

/**
 * Разово (18.11.2026, AUT-26-1013306): машины в «Без подтверждений», по которым письмо поставщика уже пришло после закрытия
 * приёма, — в «Без продажной цены» (`ReviveClosed`). Без `--apply` — списком: что и каким письмом.
 */
class ReviveClosedCommand extends Command
{
    protected $signature = 'offers:revive-closed {--apply}';

    protected $description = 'Вернуть из «Без подтверждений» машины, по которым пришло письмо поставщика';

    public function handle(ReviveClosed $revive): int
    {
        $n = 0;
        $offers = Offer::where('state', OfferState::Draft)->whereNotNull('intake_closed_at')->with('vendor')->orderBy('id')->get();
        foreach ($offers as $offer) {
            $letter = Message::whereIn('thread_id', Thread::where('offer_id', $offer->id)->select('id'))
                ->where('date_at', '>', $offer->intake_closed_at)->orderBy('date_at')->get()
                ->first(fn (Message $m) => ReviveClosed::wakes($offer, $m));
            if (! $letter) {
                continue;
            }
            $n++;
            $this->line("№ {$offer->number} {$offer->title()} ({$offer->vendor?->name}): приём закрыт {$offer->intake_closed_at->format('d.m')}, письмо {$letter->date_at->format('d.m H:i')} «{$letter->subject}»");
            if ($this->option('apply')) {
                $revive($offer, $letter);
            }
        }
        $this->info(($this->option('apply') ? 'Вернули: ' : 'Вернём: ').$n);

        return self::SUCCESS;
    }
}
