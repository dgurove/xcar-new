<?php

namespace App\Offers\Share\Console;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Share\Jobs\PrepareSharePdf;
use Illuminate\Console\Command;

/** Готовые PDF для всего, что сейчас в продаже, — один раз после выкладки (`PrepareSharePdf` дальше ставят события). */
final class PreparePdfs extends Command
{
    protected $signature = 'offers:prepare-pdfs';

    protected $description = 'Ставит сборку PDF по умолчанию (первые 10 кадров) для всех открытых предложений';

    public function handle(): int
    {
        $n = 0;
        Offer::where('state', OfferState::Open)->where('is_demo', false)->pluck('id')->each(function (int $id) use (&$n) {
            PrepareSharePdf::dispatch($id);
            $n++;
        });
        $this->info("В очереди: {$n}");

        return self::SUCCESS;
    }
}
