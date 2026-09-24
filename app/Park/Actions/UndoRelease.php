<?php

namespace App\Park\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Park\Events\VehicleAccepted;
use App\Park\EventType;
use App\Park\Inspection;
use App\Park\InspectionKind;
use App\Park\Pass;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Выдана по ошибке»: ТС снова на стоянке, на прежней площадке и месте (из ленты), осмотр выдачи стирается,
 * счета за хранение по день выдачи (SettleStorage) аннулируются, заявка на выдачу снова открыта.
 * Оплаченный при выдаче счёт откатить нельзя — сначала снять оплату.
 */
final class UndoRelease
{
    public function __construct(private VoidInvoice $void, private PurgeLetters $purge) {}

    public static function allowed(Vehicle $vehicle): bool
    {
        return $vehicle->state === VehicleState::Released && $vehicle->released_at
            && ! $vehicle->invoices()->where('state', '!=', InvoiceState::Void)->where('paid', '>', 0)->where('issued_at', '>=', $vehicle->released_at->toDateString())->exists();
    }

    public function __invoke(Vehicle $vehicle, User $by, ?string $reason = null): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $reason) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (! self::allowed($vehicle)) {
                throw ValidationException::withMessages(['state' => 'Выдачу не отменить: счёт при выдаче уже оплачен, сначала снимите оплату']);
            }
            foreach (Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'issued')->where('state', InvoiceState::Issued)->where('issued_at', '>=', $vehicle->released_at->toDateString())->get() as $invoice) {
                ($this->void)($invoice, $by, 'Выдача отменена');
            }
            // Площадка и место — где стояла в день выдачи: последняя запись приёма или перестановки в ленте.
            $last = collect($vehicle->yardTimeline())->last(fn ($t) => $t['yard_id'] !== null);
            $moved = $vehicle->events()->reorder()->whereIn('type', [EventType::Accepted, EventType::Moved])->latest('created_at')->latest('id')->first();
            Inspection::where('vehicle_id', $vehicle->id)->where('kind', InspectionKind::Release)->delete();
            $vehicle->update(['state' => VehicleState::Stored, 'released_at' => null, 'yard_id' => $last['yard_id'] ?? $vehicle->yard_id, 'spot' => $moved?->payload['spot'] ?? null]);
            // Выдали по QR — пропуск снова действует: ТС стоит, покупатель приедет с тем же кодом.
            $released = $vehicle->events()->reorder()->where('type', EventType::Released)->latest('id')->first();
            if ($passId = $released?->payload['pass'] ?? null) {
                Pass::whereKey($passId)->update(['used_at' => null, 'used_by' => null]);
            }
            $vehicle->log(EventType::ReleaseUndone, $by, array_filter(['reason' => $reason]));
            $req = Request::where('vehicle_id', $vehicle->id)->where('type', RequestType::Release)->where('state', RequestState::Done)->latest('done_at')->first();
            $req?->update(['state' => RequestState::New, 'done_at' => null, 'done_by' => null]);

            return $vehicle;
        });
        VehicleAccepted::dispatch($vehicle, null, $by);
        // Письма веток читаются заново, кадры и документы из писем возвращаются в дело из ящика.
        $this->purge->undo($vehicle);

        return $vehicle;
    }
}
