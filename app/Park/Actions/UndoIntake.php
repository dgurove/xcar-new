<?php

namespace App\Park\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Park\Doc;
use App\Park\DocState;
use App\Park\Events\VehicleRestored;
use App\Park\EventType;
use App\Park\Inspection;
use App\Park\InspectionKind;
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
 * «Принята по ошибке»: ТС снова ожидается, осмотр приёма стирается (кадры остаются), бумаги вендору, открытые
 * приёмом, снимаются, обязательство по договору комиссии аннулируется, заявка на приём снова открыта.
 * Пока хранение не выставлено и ничего не оплачено; открытая выдача или перестановка — сначала отменить их.
 */
final class UndoIntake
{
    public function __construct(private VoidInvoice $void) {}

    public static function allowed(Vehicle $vehicle): bool
    {
        return $vehicle->state === VehicleState::Stored && ! $vehicle->storage_billed_until
            && ! $vehicle->invoices()->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->exists()
            && ! $vehicle->invoices()->where('direction', 'owed')->where('state', InvoiceState::Paid)->exists();
    }

    public function __invoke(Vehicle $vehicle, User $by, ?string $reason = null): Vehicle
    {
        Nav::forgetStaffCounts();
        $vehicle = DB::transaction(function () use ($vehicle, $by, $reason) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (! self::allowed($vehicle)) {
                throw ValidationException::withMessages(['state' => 'Приём не отменить: хранение уже выставлено или есть оплаты — сначала аннулируйте счета']);
            }
            if (Request::where('vehicle_id', $vehicle->id)->whereIn('type', [RequestType::Release, RequestType::Move, RequestType::Tow])->whereIn('state', RequestState::open())->exists()) {
                throw ValidationException::withMessages(['state' => 'Сначала отмените открытую выдачу, перестановку или перегон']);
            }
            foreach (Invoice::where('vehicle_id', $vehicle->id)->where('direction', 'owed')->where('state', InvoiceState::Issued)->get() as $owed) {
                ($this->void)($owed, $by, 'Приём отменён');
            }
            $vehicle->charges()->whereNull('invoice_id')->whereNull('voided_at')->update(['voided_at' => now(), 'void_reason' => 'Приём отменён']);
            Doc::where('vehicle_id', $vehicle->id)->where('state', DocState::Pending)->delete();
            Inspection::where('vehicle_id', $vehicle->id)->where('kind', InspectionKind::Intake)->delete();
            $vehicle->update(['state' => VehicleState::Expected, 'yard_id' => null, 'spot' => null, 'accepted_at' => null, 'storage_billed_until' => null]);
            $vehicle->log(EventType::IntakeUndone, $by, array_filter(['reason' => $reason]));
            // Заявка, закрытая приёмом, снова в работе — с той же доставкой и исполнителем.
            $last = Request::where('vehicle_id', $vehicle->id)->whereIn('type', [RequestType::Intake, RequestType::Tow])->where('state', RequestState::Done)->latest('done_at')->first();
            $last?->update(['state' => $last->isTow() ? RequestState::Scheduled : RequestState::New, 'done_at' => null, 'done_by' => null]);

            return $vehicle;
        });
        VehicleRestored::dispatch($vehicle, $by);

        return $vehicle;
    }
}
