<?php

namespace App\Offers\Console;

use App\Offers\Offer;
use App\Support\Nav;
use Illuminate\Console\Command;

/**
 * Пустой черновик (`Offer::emptyDraft`) удаляется, как только из редактора ушли; закрытую вкладку редактор не
 * заметит — такие уходят ночью, старше суток.
 */
class PruneEmptyDrafts extends Command
{
    protected $signature = 'offers:prune-drafts';

    protected $description = 'Удалить брошенные пустыми черновики предложений старше суток';

    public function handle(): int
    {
        $empty = Offer::emptyDraft()->where('created_at', '<', now()->subDay())->get();
        $empty->each->delete();
        if ($empty->isNotEmpty()) {
            Nav::forgetStaffCounts();
        }
        $this->line('Удалено пустых черновиков: '.$empty->count());

        return self::SUCCESS;
    }
}
