<?php

namespace App\Http\Park;

use App\Billing\Accrual;
use App\Billing\Actions\AddCharge;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\VoidCharge;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Park\Vehicle;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Счёт и начисления из карточки ТС: отрезки хранения по плательщикам, невыставленные начисления, свободные строки. */
class VehicleInvoiceController
{
    public function create(Request $request, Vehicle $vehicle)
    {
        $vehicle->load(['vendor.party', 'ownerParty', 'offer.deal.buyer', 'brand', 'model']);
        $segments = Accrual::storage($vehicle)->filter(fn ($s) => $s['amount'] > 0)->values();
        $payers = $segments->pluck('payer')->unique()->values();
        $payer = $request->query('payer', $payers->first() ?? 'vendor');
        $party = Ledger::payerParty($vehicle, $payer) ?? ($vehicle->vendor ? Party::forVendor($vehicle->vendor) : null);
        $pending = Charge::where('vehicle_id', $vehicle->id)->whereNull('invoice_id')->whereNull('voided_at')->when($party, fn ($q) => $q->where('party_id', $party->id))->get();

        return view('park.money.create', [
            'vehicle' => $vehicle, 'segments' => $segments->where('payer', $payer)->values(), 'payer' => $payer, 'payers' => $payers,
            'party' => $party, 'parties' => Party::where('is_self', false)->orderBy('name')->pluck('name', 'id'), 'pending' => $pending,
            'dueDays' => $vehicle->vendor?->payment_days ?? 5, 'vat' => (bool) ($vehicle->vendor?->vat_included ?? false),
            'kinds' => collect(ChargeKind::cases())->reject(fn ($k) => in_array($k, [ChargeKind::Sale, ChargeKind::Selection, ChargeKind::Transfer], true))->mapWithKeys(fn ($k) => [$k->value => $k->label()]),
        ]);
    }

    public function store(Request $request, Vehicle $vehicle, IssueInvoice $issue)
    {
        $data = $request->validate([
            'party_id' => ['required', 'exists:billing_parties,id'], 'due_at' => ['required', 'date'], 'vat' => ['boolean'], 'notes' => ['nullable', 'string', 'max:1000'],
            'storage_until' => ['nullable', 'date'], 'charges' => ['nullable', 'array'], 'charges.*' => ['integer'],
            'lines' => ['nullable', 'array'], 'lines.*.title' => ['nullable', 'string', 'max:160'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.price' => ['nullable', 'numeric', 'min:0'], 'lines.*.kind' => ['nullable', Rule::enum(ChargeKind::class)],
        ]);
        $party = Party::findOrFail($data['party_id']);
        $until = $request->boolean('with_storage') && ! empty($data['storage_until']) ? Carbon::parse($data['storage_until']) : null;
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => ($l['title'] ?? '') !== '' && ($l['price'] ?? '') !== ''));
        $kind = $until ? ChargeKind::Storage : (ChargeKind::tryFrom($lines[0]['kind'] ?? '') ?? ChargeKind::Other);
        $invoice = $issue($party, $request->user(), 'issued', $kind, Carbon::parse($data['due_at']), $request->boolean('vat'), $until, array_map('intval', $data['charges'] ?? []), $lines, $vehicle, notes: $data['notes'] ?? null);

        return redirect("/money/invoices/{$invoice->id}")->with('toast', 'Счёт '.$invoice->label().' выставлен');
    }

    /** Начислить руками: услуга из прайса — цена подставлена, поправима. */
    public function charge(Request $request, Vehicle $vehicle, AddCharge $add)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ChargeKind::class)], 'title' => ['nullable', 'string', 'max:160'], 'qty' => ['nullable', 'numeric', 'min:0.01'],
            'price' => ['required', 'numeric', 'min:0'], 'party_id' => ['nullable', 'exists:billing_parties,id'],
        ]);
        $kind = ChargeKind::from($data['kind']);
        $party = ! empty($data['party_id']) ? Party::find($data['party_id']) : ($vehicle->vendor ? Party::forVendor($vehicle->vendor) : null);
        if (! $party) {
            throw ValidationException::withMessages(['party_id' => 'Некому начислить: у ТС нет вендора']);
        }
        $unit = match ($kind) {
            ChargeKind::Tow => 'pc', ChargeKind::Idle => 'h', ChargeKind::Storage => 'day', default => 'pc'
        };
        $add($party, $kind, $data['title'] ?: $kind->label(), (float) ($data['qty'] ?? 1), $unit, (float) $data['price'], $request->user(), $vehicle);

        return back()->with('toast', 'Начислено');
    }

    public function uncharge(Request $request, Vehicle $vehicle, Charge $charge, VoidCharge $void)
    {
        abort_unless($charge->vehicle_id === $vehicle->id, 404);
        $void($charge, $request->user());

        return back()->with('toast', 'Начисление снято');
    }

    /** Цена услуги из прайса для шторки «Начислить». */
    public static function priceFor(Vehicle $vehicle, ChargeKind $kind): ?float
    {
        $service = match ($kind) {
            ChargeKind::Tow => TariffService::Tow, ChargeKind::Inspection => TariffService::Inspection, ChargeKind::Idle => TariffService::Idle,
            ChargeKind::Loading => TariffService::Loading, ChargeKind::Release => TariffService::Release, default => null,
        };

        return $service ? Tariff::resolve($vehicle, $service)?->price : null;
    }
}
