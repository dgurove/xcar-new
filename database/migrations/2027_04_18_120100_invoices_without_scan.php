<?php

use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Счёт за ТС — по тому, что он печатает (18.11.2026, `Party::invoiceGaps`): идущие сделки, чей счёт ждал паспорт со сканом, а ФИО и
 * прописка покупателя уже есть, получают счёт сейчас (`SyncDealInvoices`; менеджер увидит его после «Отправить менеджеру»).
 */
return new class extends Migration
{
    public function up(): void
    {
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        Deal::where('state', DealState::Active)->where('is_demo', false)->with(['offer', 'buyer', 'contract.buyer'])->get()
            ->filter(fn (Deal $d) => ! $d->hasSaleInvoice() && ! $d->passportReady() && $d->invoiceReady())
            ->each(fn (Deal $d) => app(SyncDealInvoices::class)($d, $by));
    }

    public function down(): void {}
};
