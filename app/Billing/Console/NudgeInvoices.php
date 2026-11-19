<?php

namespace App\Billing\Console;

use App\Billing\Actions\NudgeInvoice;
use Illuminate\Console\Command;

/** Напоминания менеджерам про счета по плану («Напомнить через…», `NudgeInvoice::later`). */
class NudgeInvoices extends Command
{
    protected $signature = 'billing:nudges';

    protected $description = 'Напоминания менеджерам про счета, что наступили';

    public function handle(NudgeInvoice $nudge): int
    {
        $this->info('напомнили: '.$nudge->due());

        return self::SUCCESS;
    }
}
