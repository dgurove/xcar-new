<?php

namespace App\Mail\Console;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Jobs\ImportCandidateFiles;
use Illuminate\Console\Command;

/** Разово после выкладки: кадры писем — в медиатеки открытых кандидатов, чтобы карточки «Из писем» были с фото. */
final class ImportCandidateFilesCommand extends Command
{
    protected $signature = 'mail:candidate-files';

    protected $description = 'Подтянуть кадры писем к открытым кандидатам «Из писем»';

    public function handle(): int
    {
        $n = 0;
        Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected])->with('messages')->each(function (Candidate $c) use (&$n) {
            foreach ($c->messages as $m) {
                ImportCandidateFiles::dispatch($c->id, $m->id);
                $n++;
            }
        });
        $this->info("В очереди: {$n}");

        return self::SUCCESS;
    }
}
