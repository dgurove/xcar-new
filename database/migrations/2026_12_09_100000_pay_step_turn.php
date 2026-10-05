<?php

use App\Offers\Deal;
use App\Workflow\Position;
use App\Workflow\Requirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чей ход сейчас — у позиции, а не только у этапа (05.10.2026, владелец: Belgee X50 — в CRM «Ждём менеджера», у
 * менеджера «ждём нас», оба ждут друг друга). Пусто — как у этапа. Этап оплаты без счёта — ход наш: сделки, что уже
 * стоят там без счёта, получают его тут же, их просьба «Оплатите счёт» снимается, часы менеджеру гаснут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_positions', function (Blueprint $t) {
            $t->string('waits_for', 10)->nullable()->after('stage_id');
        });

        Position::where('track', 'sale')->with('stage.exits')->get()
            ->filter(fn (Position $p) => $p->stage?->isPayStep())
            ->each(function (Position $p) {
                $deal = Deal::where('offer_id', $p->offer_id)->where('state', 'active')->first();
                if (! $deal || $deal->hasManagerInvoice()) {
                    return;
                }
                $p->update(['waits_for' => 'us', 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
                Requirement::where('deal_id', $deal->id)->where('stage_id', $p->stage_id)->whereNull('done_at')->delete();
            });
    }

    public function down(): void
    {
        Schema::table('offer_positions', fn (Blueprint $t) => $t->dropColumn('waits_for'));
    }
};
