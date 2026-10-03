<?php

namespace App\Park\Console;

use App\Offers\Offer;
use App\Park\Sale;
use App\Park\Vehicle;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Разовый прогон после перехода на «машина одна» (03.10.2026): у связанных пар ТС ↔ предложение файлы предложения
 * переходят к ТС, тождество ТС — в предложение, пустой город — город парковки. Без `--apply` только показывает.
 */
class MirrorOffersCommand extends Command
{
    protected $signature = 'park:mirror-offers {--apply : перенести, а не только показать}';

    protected $description = 'Связанные с предложениями ТС: файлы к ТС, поля ТС в предложение';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        foreach (Vehicle::whereNotNull('offer_id')->with(['yard', 'offer' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get() as $vehicle) {
            $offer = $vehicle->offer;
            if (! $offer) {
                continue;
            }
            $own = Media::where('model_type', Offer::class)->where('model_id', $offer->id)->count();
            $car = $vehicle->media()->count();
            $this->line("ТС {$vehicle->id} ↔ № {$offer->number} {$offer->title()}: у предложения {$own}, у ТС {$car}");
            if (! $apply) {
                continue;
            }
            Sale::bring($vehicle);
            Sale::toOffer($vehicle, $offer);
            if (! $offer->settlement_id && $vehicle->yard?->settlement_id) {
                $offer->update(['settlement_id' => $vehicle->yard->settlement_id]);
            }
        }
        $this->info($apply ? 'готово' : 'с --apply перенесёт');

        return self::SUCCESS;
    }
}
