<?php

namespace App\Offers\Console;

use App\Mail\Thread;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Nav;
use Illuminate\Console\Command;

/**
 * «+ Новый» заводит черновик сразу, чтобы было куда грузить фото; брошенный пустым он висел бы в списке вечно. Ночью
 * уходят черновики старше суток без марки, VIN, номера убытка, цены, описания, фото, документов и писем — вендор,
 * подставленный с прошлого черновика, работой не считается.
 */
class PruneEmptyDrafts extends Command
{
    protected $signature = 'offers:prune-drafts';

    protected $description = 'Удалить брошенные пустыми черновики предложений старше суток';

    public function handle(): int
    {
        $empty = Offer::where('state', OfferState::Draft)->whereNull('published_at')->where('created_at', '<', now()->subDay())
            ->whereNull('brand_id')->whereNull('model_id')->whereNull('vin')->whereNull('claim_ref')->whereNull('floor_price')
            ->whereNull('asking_price')->whereNull('description')
            ->whereDoesntHave('media')->whereDoesntHave('purchaseCar')->whereDoesntHave('parkVehicle')->whereDoesntHave('positions')
            ->whereNotIn('id', Thread::whereNotNull('offer_id')->select('offer_id'))
            ->get();
        $empty->each->delete();
        if ($empty->isNotEmpty()) {
            Nav::forgetStaffCounts();
        }
        $this->line('Удалено пустых черновиков: '.$empty->count());

        return self::SUCCESS;
    }
}
