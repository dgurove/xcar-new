<?php

namespace App\Park\Console;

use App\Mail\Extraction\ParkExtractor;
use App\Park\Actions\AutoRequest;
use Illuminate\Console\Command;

/**
 * Хвост «Из писем» на приём, который пришёл до того, как заявки стали заводиться сами (`AutoRequest`). Без `--apply`
 * только показывает: что заведётся и каким типом, что останется человеку и почему. Новые письма команда не ждёт —
 * их заводит сама цепочка (`ChainBuilder::attach`).
 */
class AutoRequestsCommand extends Command
{
    protected $signature = 'park:auto-requests {--apply : завести, а не только показать} {--old : и по письмам старше недели}';

    protected $description = 'Завести заявки на приём и эвакуацию по ждущим цепочкам «Из писем»';

    public function handle(AutoRequest $auto): int
    {
        $apply = (bool) $this->option('apply');
        $old = (bool) $this->option('old');
        $done = $skipped = 0;
        foreach ($auto->waiting() as $candidate) {
            $candidate->load('messages');
            if ($why = $auto->blocker($candidate, $old)) {
                $skipped++;
                $this->line("— {$candidate->id} {$candidate->code} {$candidate->title()}: {$why}");

                continue;
            }
            $letter = $candidate->message;
            $type = $letter && ParkExtractor::asksEvacuation($letter->subject.' '.$letter->ownText()) ? 'эвакуация' : 'приём';
            $request = $apply ? $auto($candidate, $old) : null;
            $request || ! $apply ? $done++ : $skipped++;
            $this->line(($request ? "заявка {$request->id}, ТС {$request->vehicle_id}" : ($apply ? 'не завелась' : 'завести'))." {$candidate->code} {$candidate->title()}, {$type}");
        }
        $this->info($apply ? "заведено {$done}, пропущено {$skipped}" : "заведётся {$done}, пропущено {$skipped}; с --apply заведёт");

        return self::SUCCESS;
    }
}
