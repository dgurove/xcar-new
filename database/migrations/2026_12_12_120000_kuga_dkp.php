<?php

use App\Offers\Actions\IssueSelectionInvoice;
use App\Offers\CommissionMode;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ford Kuga, Т-Страхование (№ 2610041164, 05.10.2026): сделка по ДКП. Закупочная 779 000, собственнику 750 000 —
 * взаимозачёт (страховая гасит долг перед нами); покупатель Армена платит собственнику сам, Армен нам — подбор 80 000,
 * 20 000 оставляет себе. Счёт «Подбор ТС» со ссылкой — сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        $offer = Offer::where('number', 2610041164)->first();
        $deal = $offer?->deal()->first();
        if (! $deal || $deal->isDkp() || $deal->invoices()->exists()) {
            return;
        }
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        DB::transaction(function () use ($offer, $deal, $by) {
            $offer->update(['owner_price' => 750000]);
            $deal->update(['scheme' => DealScheme::OwnerDkp, 'owner_price' => 750000, 'commission_mode' => CommissionMode::Withheld, 'commission' => $deal->commission ?? 20000]);
            $offer->log(OfferEventType::Note, $by, ['text' => 'По ДКП: собственнику 750 000 ₽, взаимозачёт 29 000 ₽, вознаграждение 20 000 ₽']);
            DealContract::for($deal);
            app(IssueSelectionInvoice::class)($deal->fresh(), $by);
        });
    }

    public function down(): void {}
};
