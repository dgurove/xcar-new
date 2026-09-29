<?php

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Предложения «в сделке» без сделки (переведены кнопкой маршрута «Подтверждение принято», подтверждений нет) —
 * обратно в приём подтверждений. С этой выкладки так уйти в сделку уже нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Offer::where('state', OfferState::Sold)->whereNotIn('id', DB::table('deals')->select('offer_id'))->get() as $offer) {
            $bidding = $offer->position(Track::Sale)?->stage->workflow->stages()->where('offer_state', OfferState::Open->value)->orderBy('id')->first();
            if (! $bidding) {
                continue;
            }
            $offer->log(OfferEventType::Note, null, ['text' => 'Возвращено в приём подтверждений: переведено в сделку без подтверждения']);
            app(EnterStage::class)($offer, $bidding);
        }
    }

    public function down(): void {}
};
