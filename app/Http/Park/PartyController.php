<?php

namespace App\Http\Park;

use App\Billing\Charge;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Park\Vehicle;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** Убрать контрагента можно, пока на нём ничего не висит: ни счетов, ни вендора, ни ТС, ни человека. */
    public function destroy(Party $party)
    {
        $traces = $party->is_self || $party->invoices()->exists() || Vendor::where('party_id', $party->id)->exists()
            || Vehicle::where('owner_party_id', $party->id)->exists() || User::where('party_id', $party->id)->exists() || Charge::where('party_id', $party->id)->exists();
        if ($traces) {
            throw ValidationException::withMessages(['name' => 'Контрагент в счетах или у вендора — убрать нельзя']);
        }
        $party->delete();

        return redirect('/money/parties')->with('toast', 'Контрагент убран');
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
