<?php

namespace App\Offers\Console;

use App\Billing\Actions\VoidInvoice;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая починка прода под «счёт — после согласования с поставщиком» (09.10.2026, владелец):
 * — сделки, что стоят на согласовании (`Stage::isAgreement`), теряют неоплаченные счета и ссылки — встанут заново, когда
 *   поставщик скажет «да» (`AgreeDeal`);
 * — сделки Каркаде (оптом), которые ушли на счёт или «Согласие менеджера» без ответа поставщика, — назад на
 *   «Согласование с поставщиком», схема ПРАЙМ (выкупаем мы).
 * Без `--apply` — только что будет сделано. Счёт с оплатой или заявкой об оплате не трогаем никогда.
 */
class DealAgreementFixCommand extends Command
{
    protected $signature = 'deals:agreement-fix {--apply : сделать, а не только показать}';

    protected $description = 'Сделки на согласовании с поставщиком — без счетов; Каркаде — назад на согласование, схема ПРАЙМ';

    public function handle(VoidInvoice $void, EnterStage $enter): int
    {
        $apply = (bool) $this->option('apply');
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            $this->error('Нет ни одного админа');

            return self::FAILURE;
        }

        $deals = Deal::where('state', DealState::Active)->with(['offer.vendor', 'offer.positions.stage.exits', 'buyer'])->orderBy('id')->get();
        foreach ($deals as $deal) {
            $offer = $deal->offer;
            $stage = $offer->position(Track::Sale)?->stage;
            $wholesale = $offer->vendor?->deal_format === DealFormat::Wholesale;
            $agreement = $wholesale && $stage ? $this->agreementStage($stage->workflow_id) : null;
            // Каркаде: всё, что ещё до проверки оплаты, — на согласование заново (и та, что уже на нём, но с просьбой
            // «Подтвердите покупку» от прежнего этапа: этап правился на месте).
            $back = $agreement && ($stage->id === $agreement->id ? $deal->openRequirement()->exists() : in_array($stage->name, ['Согласие менеджера', 'Счёт выставлен менеджеру'], true));
            if (! $back && ! ($stage?->isAgreement())) {
                continue;
            }
            $prime = $wholesale && $deal->schemeOf() !== DealScheme::Prime;
            $open = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', InvoiceState::Issued)->with(['payments', 'claims'])->get()
                ->reject(fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => $p->source !== PaymentSource::Offset));
            $this->line(sprintf('№%s %s, %s: %s%s%s%s', $offer->number, $offer->vendor?->name, $deal->buyer?->shortName() ?? 'без менеджера', $stage->name,
                $open->isEmpty() ? '' : ', гасим счета '.$open->map(fn (Invoice $i) => $i->label().' на '.Money::rub($i->total))->implode(', '),
                $back ? ' → «'.$agreement->name.'»' : '', $prime ? ', схема ПРАЙМ' : ''));
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($deal, $offer, $open, $back, $prime, $agreement, $void, $enter, $by) {
                foreach ($open as $invoice) {
                    $void($invoice, $by, 'Счёт — после ответа поставщика');
                }
                $deal->forceFill(['agreed_at' => null] + ($prime ? ['scheme' => DealScheme::Prime, 'owner_price' => null, 'commission_mode' => CommissionMode::Payout] : []))->save();
                if ($back) {
                    $enter($offer->fresh(), $agreement, $by);
                }
            });
        }
        $this->info($apply ? 'Готово' : 'Только показал: запустите с --apply');

        return self::SUCCESS;
    }

    private function agreementStage(int $workflowId): ?Stage
    {
        return Stage::where('workflow_id', $workflowId)->where('waits_for', WaitsFor::Supplier)->with('exits')->get()->first(fn (Stage $s) => $s->isAgreement());
    }
}
