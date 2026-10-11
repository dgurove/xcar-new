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
        $amount = self::rubles($raw);
        $give($estimate, $amount, $request->user());

        return back()->with('toast', ($estimate->isCorrection() ? 'Ваша цена ' : 'Оценка ').Money::rub($amount).' отправлена');
    }

    /** «Отказаться от оценки» (11.10.2026): желаемая стоимость обязательна, причина — по желанию. */
    public function decline(Request $request, Offer $offer, \App\Offers\Actions\DeclineEstimate $decline)
    {
        $estimate = Estimate::openFor($offer, $request->user());
        if (! $estimate) {
            throw ValidationException::withMessages(['wish' => 'Оценка уже не нужна']);
        }
        $data = $request->validate(['wish' => ['required', 'string', 'max:30'], 'reason' => ['nullable', 'string', 'max:500']],
            ['wish.required' => 'Укажите желаемую стоимость']);
        $wish = self::rubles($data['wish']);
        $decline($estimate, $wish, $data['reason'] ?? null, $request->user());

        return redirect('/offers')->with('toast', 'Отказ отправлен');
    }

    /** Сумма в рублях как набрали: «1 150 000», «1.150.000», «1150000,50» — копейки (одна-две цифры в конце) отбрасываются. */
    private static function rubles(string $raw): int
    {
        return (int) preg_replace('/\D+/', '', preg_replace('/[,.]\d{1,2}\s*$/', '', trim($raw)));
    }
}
