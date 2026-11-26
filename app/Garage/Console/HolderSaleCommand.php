<?php

namespace App\Garage\Console;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Support\Money;
use App\Users\User;
use Illuminate\Console\Command;

/**
 * Живые сделки покупателей с гаражом — на цену продажи (26.11.2026, владелец: «он не должен нигде видеть цену закупа», вознаграждения
 * нет): вознаграждение снимается; счёт на его цену гаража, по которому не платили, аннулируется, если не совпадает с ценой продажи
 * его клиенту, — новый встанет сам, когда будут цена и клиент (`SyncDealInvoices::prime`). Оплаченные не трогаются; у оплаченной без
 * цены продажи цена — сумма оплаченного счёта (деньги уже пришли, договор встаёт на неё же). Без `--apply` — список.
 */
class HolderSaleCommand extends Command
{
    protected $signature = 'garage:holder-sale {--apply}';

    protected $description = 'Сделки покупателей с гаражом — договор и счёт по цене продажи, без вознаграждения';

    public function handle(VoidInvoice $void, SyncDealInvoices $sync): int
    {
        $apply = (bool) $this->option('apply');
        $by = User::withRole(\App\Users\Role::Admin)->orderBy('id')->firstOrFail();
        // Идущие и закрытые, где машина ещё продаётся (`Deal::holderEditable`).
        $deals = Deal::whereNotNull('buyer_id')->whereHas('garageCar')->with(['offer', 'buyer', 'contract', 'garageCar'])->orderBy('id')->get()
            ->filter(fn (Deal $d) => $d->forGarageBuyer() && $d->holderEditable());
        foreach ($deals as $deal) {
            $car = $deal->garageCar;
            $invoice = $car?->holderInvoice();
            // Оплаченный счёт за ТС без цены продажи — цена из оплаты.
            $fromPaid = $deal->amount === null && $invoice?->state === InvoiceState::Paid ? (int) round((float) $invoice->total) : null;
            $paid = (bool) $car?->buyerHasPaid();
            $client = $deal->payer_party_id || $deal->contract?->buyer_user_id;
            $stale = $invoice && ! $paid && $invoice->state !== InvoiceState::Paid
                && ($deal->amount === null || ! $client || abs((float) $invoice->total - (float) $deal->amount) >= 0.01);
            $this->line($deal->offer?->number.' '.$deal->offer?->title().' — '.$deal->buyer?->name
                .', цена продажи '.($deal->amount !== null ? Money::rub($deal->amount) : ($fromPaid ? 'нет → '.Money::rub($fromPaid).' из оплаты' : 'нет'))
                .($deal->commission !== null ? ', вознаграждение '.Money::rub($deal->commission).' → снять' : '')
                .($invoice ? ', счёт '.$invoice->label().' '.Money::rub($invoice->total).($paid ? ' (платили — не трогаю)' : ($stale ? ' → аннулировать' : ' — оставить')) : ', счёта нет')
                .($client ? '' : ', клиента нет'));
            if (! $apply) {
                continue;
            }
            $deal->commission !== null && $deal->update(['commission' => null]);
            $fromPaid && $deal->update(['amount' => $fromPaid]);
            $stale && $void($invoice, $by, 'Счёт покупателю с гаражом — по цене продажи его клиенту');
            $sync($deal->fresh(), $by);
        }
        $this->info(($apply ? 'Готово: ' : 'Будет: ').$deals->count());

        return self::SUCCESS;
    }
}
