<?php

namespace App\Cars\Console;

use App\Cars\Vin\RememberVin;
use Illuminate\Console\Command;

/** Один раз после появления памяти: декодер запоминает все машины базы. */
final class LearnVins extends Command
{
    protected $signature = 'vin:learn';

    protected $description = 'Запоминает VIN всех машин базы для декодера';

    public function handle(): int
    {
        $n = RememberVin::learnAll();
        $this->info("Записей: {$n}");

        return self::SUCCESS;
    }
}
