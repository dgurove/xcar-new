<?php

namespace App\Mail\Console;

use App\Mail\Actions\CloseChain;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use Illuminate\Console\Command;

/**
 * Цепочка «Из писем», по которой давно не пишут, закрывается: ТС уехала без письма о выдаче или заявка
 * не состоялась. Письма замораживаются и в пересборку не идут. Решение Дмитрия: 60 дней от последнего письма.
 */
class ArchiveStaleCandidates extends Command
{
    public const DAYS = 60;

    protected $signature = 'mail:archive-stale {--days=60}';

    protected $description = 'Кандидаты «Из писем» без писем дольше 60 дней закрываются';

    public function handle(CloseChain $close): int
    {
        $before = now()->subDays((int) $this->option('days'));
        $stale = Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->where('last_message_at', '<', $before)->get();
        foreach ($stale as $candidate) {
            $close($candidate, 'stale');
        }
        $this->line("Закрыто: {$stale->count()}");

        return self::SUCCESS;
    }
}
