<?php

namespace App\Http\Park;

use App\Billing\Party;
use App\Billing\PartyKind;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Реквизиты: мы первой строкой, дальше контрагенты — юрлица и физлица. */
class PartyController
{
    public function index()
    {
        return view('park.money.parties', ['parties' => Party::withCount('invoices')->orderByDesc('is_self')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $party = Party::create($this->data($request));

        return back()->with('toast', $party->name.' — добавлен');
    }

    public function update(Request $request, Party $party)
    {
        $party->update($this->data($request));

        return back()->with('toast', 'Сохранено');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::enum(PartyKind::class)], 'name' => ['required', 'string', 'max:200'],
            'inn' => ['nullable', 'digits_between:10,12'], 'kpp' => ['nullable', 'digits:9'], 'ogrn' => ['nullable', 'digits_between:13,15'],
            'legal_address' => ['nullable', 'string', 'max:255'], 'director' => ['nullable', 'string', 'max:120'], 'director_basis' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'], 'bik' => ['nullable', 'digits:9'], 'account' => ['nullable', 'digits:20'], 'corr_account' => ['nullable', 'digits:20'],
            'passport' => ['nullable', 'string', 'max:60'], 'passport_issued' => ['nullable', 'string', 'max:255'], 'reg_address' => ['nullable', 'string', 'max:255'], 'birth_at' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'], 'payment_purpose' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
