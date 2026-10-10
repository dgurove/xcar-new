<?php

use App\Logistics\Choices;
use App\Logistics\Transport;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * Logistics XCar заменяет «Вывоз» CRM (10.10.2026): открытые вывозы «Мы», где ТС ещё не забрали, становятся перевозками
 * «Ждёт логиста» — регион по городу предложения, откуда — адрес осмотра; кто везёт, админ или логист дозаполнят. Вывозы,
 * где везёт менеджер, остаются «Везёт сам».
 */
return new class extends Migration
{
    public function up(): void
    {
        Offer::whereNotNull('evacuation_to')->whereNull('evacuator_id')
            ->whereHas('positions', fn ($p) => $p->where('track', Track::Service))
            ->whereNotIn('state', [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived])
            ->with(['settlement', 'positions.stage.exits', 'positions.stage.block'])->get()
            ->reject(fn (Offer $o) => $o->pickedUp() || Transport::openFor($o))
            ->each(fn (Offer $o) => Transport::create([
                'offer_id' => $o->id, 'scope' => 'local', 'from_region_id' => Choices::regionOf($o),
                'from_address' => $o->inspection_address, 'state' => 'new',
            ]));
    }

    public function down(): void {}
};
