<?php

use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Подбор ждёт вознаграждения (22.11.2026, владелец: «пока явно не указали, что вознаграждение 0, нельзя писать, что он нам якобы
 * должен 80 тыщ»): у идущих сделок «страховой напрямую» и ДКП без вознаграждения системный счёт подбора, менеджеру не отправленный,
 * гасится (`SyncDealInvoices::dropPendingSelection`); в «Оплатах» вместо суммы — «назначить вознаграждение».
 */
return new class extends Migration
{
    public function up(): void
    {
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        Deal::where('state', DealState::Active)->where('is_demo', false)->whereNull('commission')->with(['offer', 'buyer', 'contract.buyer'])->get()
            ->filter(fn (Deal $d) => $d->rewardPending() && ($d->paysSelection() || $d->splitsSelection()))
            ->each(fn (Deal $d) => app(SyncDealInvoices::class)($d, $by));
    }

    public function down(): void {}
};
