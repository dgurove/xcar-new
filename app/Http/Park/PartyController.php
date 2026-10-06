<?php

namespace App\Http\Park;

use App\Billing\Charge;
use App\Billing\Party;
use App\Billing\PartyRules;
use App\Park\Vehicle;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Реквизиты: мы первой строкой, дальше контрагенты — юрлица и физлица. */
class PartyController
{
    /** Справочник — по имени (владелец 06.10.2026), мы первой строкой; дата и число счетов — выбором. */
    public const SORTS = ['name' => ['Имя', 'asc'], 'created' => ['Дата добавления', 'desc'], 'invoices' => ['Счета', 'desc']];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'park-parties');
        $sort = Sort::from($request->query('sort'), self::SORTS, 'name');

        return view('park.money.parties', [
            'parties' => Party::withCount('invoices')->orderByDesc('is_self')
                ->orderBy(match ($sort->key) { 'created' => 'created_at', 'invoices' => 'invoices_count', default => 'name' }, $sort->dir())->orderBy('id')->get(),
            'sort' => $sort,
        ]);
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
            throw ValidationException::withMessages(['name' => 'Контрагент в счетах или у вендора, убрать нельзя']);
        }
        $party->delete();

        return redirect('/money/parties')->with('toast', 'Контрагент убран');
    }

    private function data(Request $request): array
    {
        return $request->validate(PartyRules::rules()) + ['vat_on_top' => $request->boolean('vat_on_top')];
    }
}
