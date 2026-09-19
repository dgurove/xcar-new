<?php

namespace App\Park\Console;

use App\Park\Today;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\ParkDigest;
use Illuminate\Console\Command;

/** Утренняя сводка стоянки владельцу в Telegram; в выходные и пустая — не шлётся. */
class ParkDigestCommand extends Command
{
    protected $signature = 'park:digest';

    protected $description = 'Сводка стоянки владельцу: просрочено, забрать, принять, выдать, стоят долго';

    public function handle(): int
    {
        $today = Today::build(null);
        $counts = collect($today['sections'])->mapWithKeys(fn ($s) => [$s[0] => $s[2]->count()])->all()
            + ['На стоянке' => $today['stats']['На стоянке']];
        if (collect($today['sections'])->sum(fn ($s) => $s[2]->count()) === 0) {
            return self::SUCCESS;
        }
        NotifyOwner::dispatch(new ParkDigest($counts));
        $this->info(json_encode($counts, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
