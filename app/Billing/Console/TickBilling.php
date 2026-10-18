<?php

namespace App\Billing\Console;

use App\Billing\Actions\TickInvoices;
use Illuminate\Console\Command;

/** Часы денег: просроченные счета — отметка раз. */
class TickBilling extends Command
{
    protected $signature = 'billing:tick';

    protected $description = 'Просроченные счета: отметка';

    public function handle(TickInvoices $tick): int
    {
        $this->info('просрочено: '.$tick());

        return self::SUCCESS;
    }
}
