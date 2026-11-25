<?php

namespace App\Offers\Console;

use App\Offers\Actions\FillOwnerFromPassport;
use App\Offers\Deal;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Разово (25.11.2026): идущим сделкам ДКП собственника без продавца — продавец из паспорта в письмах страховой
 * (`FillOwnerFromPassport`). Без `--apply` — какие файлы встанут сканом; с ним — запись, чтение паспорта — заданиями.
 */
class OwnerPassportsCommand extends Command
{
    protected $signature = 'deals:owner-passports {--apply}';

    protected $description = 'Продавец ДКП собственника из паспорта в письмах страховой';

    public function handle(FillOwnerFromPassport $fill): int
    {
        $apply = (bool) $this->option('apply');
        $n = 0;
        foreach (Deal::with(['offer.vendor', 'contract.seller'])->active()->get()->filter(fn (Deal $d) => $d->isDkp()) as $deal) {
            $files = $fill($deal, $apply);
            if ($files) {
                $n++;
                $this->line("сделка {$deal->id} {$deal->offer->title()} ({$deal->offer->vendor?->name}): ".implode(', ', array_map(fn (Media $m) => $m->file_name, $files)));
            }
        }
        $this->info(($apply ? 'Встало' : 'Встанет').": {$n}");

        return self::SUCCESS;
    }
}
