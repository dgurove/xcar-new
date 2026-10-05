<?php

namespace App\Billing\Acquiring\Console;

use App\Billing\Acquiring\Actions\EnsurePayLink;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use Illuminate\Console\Command;

/**
 * Ссылка вместе со счётом появилась 05.10.2026 — счетам, выставленным раньше, её заводит этот прогон (один раз при
 * выкладке). Повтор безопасен: у счёта с открытой ссылкой ничего не меняется (`EnsurePayLink`).
 */
final class BackfillPayLinks extends Command
{
    protected $signature = 'acquiring:backfill-links';

    protected $description = 'Заводит ссылку на оплату открытым счетам сделок и гаража, у которых её нет';

    public function handle(EnsurePayLink $ensure): int
    {
        $made = 0;
        Invoice::where('direction', 'issued')->where('state', InvoiceState::Issued)->orderBy('id')
            ->each(function (Invoice $i) use ($ensure, &$made) {
                $before = $i->openLink()?->id;
                $link = $ensure($i);
                $made += $link && $link->id !== $before ? 1 : 0;
            });
        $this->info('Ссылок заведено: '.$made);

        return self::SUCCESS;
    }
}
