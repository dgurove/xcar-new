<?php

namespace App\Mail\Console;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Support\Nav;
use Illuminate\Console\Command;

/**
 * Цепочка «Из писем», по которой давно не пишут, — в архив: ТС уехала без письма о выдаче или заявка
 * не состоялась. Из архива её можно вернуть и завести. Решение Дмитрия: 60 дней от последнего письма.
 */
class ArchiveStaleCandidates extends Command
{
    public const DAYS = 60;

    protected $signature = 'mail:archive-stale {--days=60}';

    protected $description = 'Кандидаты «Из писем» без писем дольше 60 дней — в архив';

    public function handle(ArchiveThread $archive): int
    {
        $before = now()->subDays((int) $this->option('days'));
        $stale = Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->where('last_message_at', '<', $before)->get();
        foreach ($stale as $candidate) {
            $candidate->forceFill(['state' => CandidateState::Rejected])->saveQuietly();
            $archive->candidate($candidate);
        }
        if ($stale->isNotEmpty()) {
            Nav::forgetStaffCounts();
        }
        $this->line("В архив: {$stale->count()}");

        return self::SUCCESS;
    }
}
