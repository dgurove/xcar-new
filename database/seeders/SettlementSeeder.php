<?php

namespace Database\Seeders;

use App\Cars\SettlementImport;
use Illuminate\Database\Seeder;

/** Населённые пункты и регионы из ОКТМО — тем же импортом, что миграция; загруженный справочник не трогает. */
class SettlementSeeder extends Seeder
{
    public function run(SettlementImport $import): void
    {
        $import();
    }
}
