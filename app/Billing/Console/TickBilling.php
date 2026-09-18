<?php

namespace App\Billing\Console;

use App\Billing\Actions\TickInvoices;
use Illuminate\Console\Command;

/** Часы денег: просроченные счета — раз, напоминание владельцу раз в неделю. */
class TickBilling extends Command
{
    protected $signature = 'billing:tick';

    protected $description = 'Просроченные счета: отметка и напоминание владельцу';

    public function handle(TickInvoices $tick): int
    {
        $this->info('просрочено: '.$tick());

        return self::SUCCESS;
    }
}
