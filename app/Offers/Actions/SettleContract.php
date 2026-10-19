<?php

namespace App\Offers\Actions;

use App\Notifications\ContractReadyNotice;
use App\Offers\DealContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Договор собрался — одна дверь для всех путей (данные ДКП, скан паспорта покупателя): впервые готов — `ready_at` и
 * менеджеру «ДКП готов». Номер и дата ДКП нашего юрлица (19.10.2026, владелец: «номер и дата сами»): у ПРАЙМ и Р.О.С.
 * свой сквозной номер за год («ПР-15/2026»), дата — день, когда договор собрался (по Москве). Номер у этого продавца уже
 * есть — не трогаем; сменился продавец (ПРАЙМ → Р.О.С.) — новый у нового. ДКП собственника (Т-Страхование) без номера.
 */
final class SettleContract
{
    public function __invoke(DealContract $contract): DealContract
    {
        if (! $contract->isReady()) {
            return $contract;
        }
        $this->number($contract);
        if (! $contract->ready_at) {
            $contract->update(['ready_at' => now()]);
            $contract->deal->buyer?->notify(new ContractReadyNotice($contract->deal));
        }

        return $contract;
    }

    private function number(DealContract $contract): void
    {
        $seller = $contract->deal->seller();
        if ($contract->form() === 'owner' || ($contract->number && $contract->issuer === $seller)) {
            return;
        }
        $today = now('Europe/Moscow');
        Cache::lock('contract-number:'.$seller->value, 10)->block(10, function () use ($contract, $seller, $today) {
            $next = (int) DB::table('deal_contracts')->where('issuer', $seller->value)->where('year', $today->year)->max('number') + 1;
            $contract->forceFill(['issuer' => $seller, 'year' => $today->year, 'number' => $next, 'signed_at' => $contract->signed_at ?? $today->toDateString()])->save();
        });
    }
}
