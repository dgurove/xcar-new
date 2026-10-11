<?php

namespace App\Http\Site;

use App\Offers\Actions\GiveEstimate;
use App\Offers\Estimate;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Менеджер оценивает цену продажи черновика, который ему отдали на оценку (11.10.2026). Копейки отбрасываются. */
class EstimateController
{
    public function store(Request $request, Offer $offer, GiveEstimate $give)
    {
        $estimate = Estimate::openFor($offer, $request->user());
        if (! $estimate) {
            throw ValidationException::withMessages(['amount' => 'Оценка уже не нужна']);
        }
        // Корректировка: «Согласен» — его сумма и есть цена админа.
        if ($request->boolean('agree') && $estimate->isCorrection()) {
            $give($estimate, $estimate->base_price, $request->user());

            return back()->with('toast', 'Вы согласны с ценой '.Money::rub($estimate->base_price));
        }
        $raw = (string) $request->validate(['amount' => ['required', 'string', 'max:30']], ['amount.required' => 'Укажите цену'])['amount'];
        $amount = (int) preg_replace('/\D+/', '', preg_split('/[,.]/', $raw)[0]);
        $give($estimate, $amount, $request->user());

        return back()->with('toast', ($estimate->isCorrection() ? 'Ваша цена ' : 'Оценка ').Money::rub($amount).' отправлена');
    }
}
