<?php

namespace App\Billing;

use App\Park\Vehicle;
use App\Park\VehicleState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Долги парковки (счета ИП): по контрагенту, по ТС, по всем — из счетов минус оплаты; не выставленное — отдельной цифрой. */
final class Ledger
{
    /** @return array{owed_to_us: float, we_owe: float, overdue: float} */
    public static function debtOf(Party $party): array
    {
        $open = Invoice::ofSeller(Seller::Park)->where('party_id', $party->id)->where('state', InvoiceState::Issued)->get();

        return [
            'owed_to_us' => round($open->where('direction', 'issued')->sum(fn (Invoice $i) => $i->remaining()), 2),
            'we_owe' => round($open->where('direction', 'owed')->sum(fn (Invoice $i) => $i->remaining()), 2),
            'overdue' => round($open->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2),
        ];
    }

    /**
     * Все контрагенты с открытыми счетами или не выставленным хранением — и по выданным ТС тоже, пока хвост
     * хранения не выставлен. Чтение базу не меняет: контрагент без строки — черновик с ключом по вендору.
     *
     * @return Collection<int, array{party: Party, owed_to_us: float, we_owe: float, overdue: float, unbilled: float}>
     */
    public static function debts(): Collection
    {
        $open = Invoice::ofSeller(Seller::Park)->with('party')->where('state', InvoiceState::Issued)->get()->groupBy('party_id');
        $unbilled = [];
        $vehicles = Vehicle::with(['vendor.party', 'offer.deal.buyer', 'ownerParty'])->whereIn('state', [VehicleState::Stored, VehicleState::InTransit, VehicleState::Released])->whereNotNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('released_at')->orWhereNull('storage_billed_until')->orWhereColumn('storage_billed_until', '<', 'released_at'))->get();
        foreach ($vehicles as $v) {
            foreach (Accrual::storage($v) as $s) {
                $party = self::payerParty($v, $s['payer'], false);
                if ($party && $s['amount'] > 0) {
                    $key = $party->id ?? 'v'.$v->vendor_id;
                    $unbilled[$key] = ['party' => $party, 'amount' => ($unbilled[$key]['amount'] ?? 0) + $s['amount']];
                }
            }
        }
        foreach (Charge::with('party')->whereNull('invoice_id')->whereNull('voided_at')->get() as $c) {
            $unbilled[$c->party_id] = ['party' => $c->party, 'amount' => ($unbilled[$c->party_id]['amount'] ?? 0) + $c->amount];
        }
        $ids = $open->keys()->merge(array_keys($unbilled))->unique();

        return $ids->map(function ($id) use ($open, $unbilled) {
            $invoices = $open->get($id, collect());
            $party = $invoices->first()?->party ?? $unbilled[$id]['party'];

            return [
                'party' => $party,
                'owed_to_us' => round($invoices->where('direction', 'issued')->sum(fn (Invoice $i) => $i->remaining()), 2),
                'we_owe' => round($invoices->where('direction', 'owed')->sum(fn (Invoice $i) => $i->remaining()), 2),
                'overdue' => round($invoices->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2),
                'unbilled' => round($unbilled[$id]['amount'] ?? 0, 2),
            ];
        })->sortByDesc(fn ($d) => $d['overdue'] * 1000 + $d['owed_to_us'] + $d['unbilled'])->values();
    }

    /** Неоплаченное по ТС — для выдачи и красной пилюли. */
    public static function vehicleDebt(Vehicle $vehicle): float
    {
        return round(Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'issued')->where('state', InvoiceState::Issued)->get()->sum(fn (Invoice $i) => $i->remaining()), 2);
    }

    /** Неоплаченное по многим ТС разом — красный чип в строках списка. @return array<int, float> */
    public static function debtsByVehicle(array $ids): array
    {
        return Invoice::whereIn('vehicle_id', $ids)->where('direction', 'issued')->where('state', InvoiceState::Issued)->get()
            ->groupBy('vehicle_id')->map(fn ($invoices) => round($invoices->sum(fn (Invoice $i) => $i->remaining()), 2))->filter(fn ($d) => $d > 0)->all();
    }

    /**
     * Невыставленные дни покупателя: при выдаче их берут наличными на месте, поэтому они держат выдачу.
     */
    public static function buyerUnbilled(Vehicle $vehicle, ?CarbonInterface $until = null): float
    {
        return round((float) Accrual::storage($vehicle, $until)->where('payer', 'buyer')->sum('amount'), 2);
    }

    /**
     * Невыставленное вендору и страхователю: хранение по день выдачи и начисления вне счёта. Выдачу это не
     * держит — страховая платит по счёту раз в месяц, — но сумму показываем при выдаче словами.
     */
    public static function vendorUnbilled(Vehicle $vehicle, ?CarbonInterface $until = null): float
    {
        $storage = (float) Accrual::storage($vehicle, $until)->where('payer', '!=', 'buyer')->sum('amount');
        $charges = (float) Charge::where('vehicle_id', $vehicle->id)->whereNull('invoice_id')->whereNull('voided_at')->sum('amount');

        return round($storage + $charges, 2);
    }

    /** Кому можно начислить по ТС: вендор, страхователь, покупатель — те, у кого есть контрагент (для чтения не создаются). @return array<int, string> */
    public static function payersOf(Vehicle $vehicle): array
    {
        $out = [];
        foreach (['vendor' => 'вендор', 'owner' => 'страхователь', 'buyer' => 'покупатель'] as $payer => $label) {
            if (($party = self::payerParty($vehicle, $payer, false)) && $party->id) {
                $out[$party->id] = $party->name.' — '.$label;
            }
        }

        return $out;
    }

    /** Кто платит отрезок хранения: вендор, страхователь или покупатель — их контрагенты; «никто» — null. */
    public static function payerParty(Vehicle $vehicle, string $payer, bool $create = true): ?Party
    {
        $vehicle->loadMissing(['vendor', 'ownerParty', 'buyerParty', 'offer.deal.buyer']);

        return match ($payer) {
            'vendor' => $vehicle->vendor ? Party::forVendor($vehicle->vendor, $create) : null,
            'owner' => $vehicle->ownerParty,
            'buyer' => $vehicle->buyerParty ?? (($buyer = $vehicle->offer?->deal?->buyer) ? Party::forUser($buyer, $create) : Party::forPickup($vehicle, $create)),
            default => null,
        };
    }
}
