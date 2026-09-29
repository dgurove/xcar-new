<?php

use App\Offers\Offer;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Preset;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * Каркаде — машины уже наши: маршрут продажи «Своя машина на парковке» (согласие, счёт, выдача с парковки)
 * с каждым предложением. Уже заведённые черновики и открытые встают на него по своему состоянию.
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendor = Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->first();
        if (! $vendor) {
            return;
        }
        $workflow = $vendor->workflowOrNew(Track::Sale);
        if (! $workflow->stages()->exists()) {
            $workflow->update(['auto_start' => true]);
            app(ApplyPreset::class)($workflow, Preset::Stock);
        }
        $start = app(StartRoute::class);
        Offer::where('vendor_id', $vendor->id)->whereIn('state', ['draft', 'open'])->whereDoesntHave('positions')
            ->each(fn (Offer $offer) => $start($offer->load('vendor.workflows')));
    }

    public function down(): void {}
};
