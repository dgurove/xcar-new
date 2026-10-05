<?php

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Срок подбора — с шага оплаты (06.10.2026, владелец: «почему он уже должник, если ещё не забрал и не продал»):
 * `billing_invoices.due_at` может быть пустым, а у неоплаченного подбора сделки, что до шага оплаты ещё не дошла,
 * срок снимается. Шаг оплаты поставит его сам (`StartSelectionDue`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table billing_invoices alter column due_at drop not null');

        Invoice::withoutGlobalScopes()->where('direction', 'issued')->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)
            ->whereNotNull('deal_id')->get()->each(function (Invoice $invoice) {
                $deal = Deal::withoutGlobalScopes()->find($invoice->deal_id);
                if (! $deal?->isActive() || $deal->isGarage() || $invoice->claimed() > 0) {
                    return;
                }
                $position = $deal->offer?->position(Track::Sale);
                if (! $position || $position->stage->isPayStep()) {
                    return;
                }
                $stages = Stage::where('workflow_id', $position->stage->workflow_id)->with('exits')->get();
                if ($stages->contains(fn (Stage $s) => $s->isPayStep())) {
                    $invoice->forceFill(['due_at' => null, 'overdue_at' => null, 'reminded_at' => null])->save();
                }
            });
    }

    public function down(): void {}
};
