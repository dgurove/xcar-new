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
        $raw = (string) $request->validate(['amount' => ['required', 'string', 'max:30']], ['amount.required' => 'Укажите цену'])['amount'];
        $amount = (int) preg_replace('/\D+/', '', preg_split('/[,.]/', $raw)[0]);
        $estimate = Estimate::openFor($offer, $request->user());
        if (! $estimate) {
            throw ValidationException::withMessages(['amount' => 'Оценка уже не нужна']);
        }
        $give($estimate, $amount, $request->user());

        return back()->with('toast', 'Оценка '.Money::rub($amount).' отправлена');
    }
}
