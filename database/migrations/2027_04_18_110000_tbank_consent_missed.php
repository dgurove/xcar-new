<?php

use App\Offers\DealState;
use App\Offers\SupplierLetterWait;
use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * Т-Страхование, ПСБ, РСХБ, ЗЕСТ: сделки на «Письме о покупке» без согласия менеджера — на «Ждём письмо поставщика» (18.11.2026,
 * владелец по AUT-26-971451: «договаривались — сначала спрашиваем, берёт ли в работу, потом отвечаем, а она без „берём в работу“
 * попала в „Написать поставщику“»). Её приняли 15.11 в 07:51, до перевода маршрута на «сначала согласие»
 * (`tbank_manager_confirm_first`), а тот перевёл только шаги, что уже ждали нас: эта ждала поставщика. Берём те, где наше письмо не
 * ушло и «Беру в работу» с начала сделки не было; письмо поставщика есть — дальше «Спросить менеджера» (`SupplierLetterWait`).
 */
return new class extends Migration
{
    private const VENDORS = ['Т-Страхование', 'ПСБ Страхование', 'РСХБ-Страхование', 'ЗЕСТ'];

    /** Менеджер согласился до перевода маршрута (`tbank_manager_confirm_first`). */
    private const AGREED = ['AUT-26-920056'];

    public function up(): void
    {
        foreach (Vendor::whereIn('name', self::VENDORS)->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
            $claimed = $stages['Письмо о покупке'] ?? null;
            $wait = $stages[SupplierLetterWait::STAGE] ?? null;
            if (! $claimed || ! $wait) {
                continue;
            }
            Position::where('stage_id', $claimed->id)->where('track', Track::Sale->value)->with(['stage', 'offer.deal'])->get()
                ->filter(function (Position $p) {
                    $deal = $p->offer?->deal;
                    if (! $deal || $deal->state !== DealState::Active || in_array($p->offer->claim_ref, self::AGREED, true)) {
                        return false;
                    }
                    if (isset($p->payload['letter_at']) || isset($p->payload['reply_at']) || ! empty($p->payload['letter_by_hand'])) {
                        return false;
                    }

                    return ! $p->offer->events()->where('type', 'stage_entered')->where('created_at', '>=', $deal->created_at)
                        ->where('payload->exit', 'Беру в работу')->exists();
                })
                ->each(fn (Position $p) => app(EnterStage::class)($p->offer, $wait->fresh('exits')));
        }
    }

    public function down(): void {}
};
