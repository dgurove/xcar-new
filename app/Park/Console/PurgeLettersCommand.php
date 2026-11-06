<?php

namespace App\Park\Console;

use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Jobs\PurgeReleasedLetters;
use App\Park\LettersPurge;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;

/**
 * Ночью (06.11.2026): выданные ТС, чей месяц выдачи закрыт и оплачен (`LettersPurge::due`), — файлы из писем стираются,
 * письма замораживаются (`PurgeReleasedLetters` → `PurgeLetters`). Раньше это шло сразу при выдаче и съедало акты, по которым
 * сверяют даты для счёта и акта за месяц. Без `--apply` — только показать.
 */
class PurgeLettersCommand extends Command
{
    protected $signature = 'park:purge-letters {--apply : поставить очистку, а не только показать}';

    protected $description = 'Почистить файлы из писем у выданных ТС, чей месяц выдачи закрыт и оплачен';

    public function handle(): int
    {
        $n = 0;
        Vehicle::with('vendor')->where('state', VehicleState::Released)->where('released_at', '<', now()->startOfMonth())
            ->where(fn ($q) => $q->whereHas('media', fn ($m) => $m->where('custom_properties->source', 'mail')->where('custom_properties->park', true))
                ->orWhereIn('id', Thread::park()->whereNotNull('vehicle_id')->whereIn('id', Message::whereNull('frozen_at')->select('thread_id'))->select('vehicle_id')))
            ->chunkById(200, function ($vehicles) use (&$n) {
                foreach ($vehicles as $v) {
                    if (! LettersPurge::due($v)) {
                        continue;
                    }
                    $n++;
                    $this->option('apply') ? PurgeReleasedLetters::dispatch($v->id) : $this->line("ТС {$v->id} {$v->titleWithYear()}, выдана {$v->released_at->format('d.m.Y')}");
                }
            });
        $this->info(($this->option('apply') ? 'Поставлено' : 'К очистке').": {$n}");

        return self::SUCCESS;
    }
}
