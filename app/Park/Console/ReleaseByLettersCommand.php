<?php

namespace App\Park\Console;

use App\Park\Actions\ReleaseByLetters;
use Illuminate\Console\Command;

/**
 * Стоящие ТС, о выдаче которых мы уже написали вендору. Без `--apply` только показывает, что будет выдано и что нет
 * и почему; дальше это делает само (`Mail\OnMessage` на наше письмо, `park:tick`).
 */
class ReleaseByLettersCommand extends Command
{
    protected $signature = 'park:release-by-letters {--apply : выдать, а не только показать}';

    protected $description = 'Выдать стоящие ТС, о выдаче которых мы уже написали вендору';

    public function handle(ReleaseByLetters $release): int
    {
        $apply = (bool) $this->option('apply');
        $done = $skipped = 0;
        foreach ($release->suspects() as $v) {
            $letter = $release->letter($v);
            $name = "ТС {$v->id} {$v->titleWithYear()} {$v->ref}";
            if (! $letter) {
                $skipped++;
                $this->line("— {$name}: письмо о выдаче раньше приёма или вендор писал после него");

                continue;
            }
            $done++;
            if ($apply) {
                $release($v);
            }
            $this->line(($apply ? 'выдана ' : 'выдать ').$name.', '.$letter->date_at->format('d.m.Y').': '.$letter->subject);
        }
        $this->info($apply ? "выдано {$done}, пропущено {$skipped}" : "выдастся {$done}, пропущено {$skipped}; с --apply выдаст");

        return self::SUCCESS;
    }
}
