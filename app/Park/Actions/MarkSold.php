<?php

namespace App\Park\Actions;

use App\Mail\Message;
use App\Park\Events\VehicleSold;
use App\Park\EventType;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Страховая продала ТС: с даты письма идут дни за счёт вендора, дальше платит покупатель; кому выдать — из письма.
 * Тут же заявка на выдачу с контактом покупателя — она и попадает в пресет «Выдача» заявок. Повтор — правка полей.
 */
final class MarkSold
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Vehicle $vehicle, ?User $by, CarbonInterface $soldAt, ?string $name, ?string $phone, ?Message $message = null, ?string $freeUntil = null): Vehicle
    {
        $fresh = ! $vehicle->sold_at;
        Nav::forgetStaffCounts();
        // Срок «забрать до …» из письма — только там, где опоздавший покупатель платит (ВСК, Альфа СПб);
        // у остальных он ничего не значит, а пустой не стирает вписанное руками.
        $free = $freeUntil && ($vehicle->vendor?->buyer_pays_late ?? false) ? $freeUntil : null;
        DB::transaction(function () use ($vehicle, $by, $soldAt, $name, $phone, $message, $fresh, $free) {
            $vehicle->update(array_filter([
                'sold_at' => $soldAt->toDateString(), 'sold_message_id' => $message?->id, 'buyer_free_until' => $free,
                'pickup_name' => $name, 'pickup_phone' => $phone,
            ], fn ($v) => $v !== null) + ($fresh ? [] : ['pickup_name' => $name, 'pickup_phone' => $phone]));
            // Новому покупателю — новый контрагент, если прежнему уже выставляли счета: переименовать значило бы
            // переписать плательщика в уже выставленных документах. Без счетов контрагент просто правится.
            if ($vehicle->buyerParty && $name) {
                if ($vehicle->buyerParty->name !== $name && self::billed($vehicle)) {
                    $vehicle->update(['buyer_party_id' => null]);
                } else {
                    $vehicle->buyerParty->update(['name' => $name, 'phone' => $phone]);
                }
            }
            $vehicle->log($fresh ? EventType::Sold : EventType::Updated, $by, $fresh
                ? array_filter(['who' => trim(($name ?? '').' '.($phone ?? '')) ?: null, 'thread' => $message?->thread_id])
                : ['fields' => ['sold_at', 'pickup_name']]);
            if ($fresh && $vehicle->state === VehicleState::Stored && ! $vehicle->openRequest(RequestType::Release)) {
                ($this->create)($by, RequestType::Release, $vehicle,
                    ['contact_name' => $name, 'contact_phone' => $phone, 'thread_id' => $message?->thread_id]);
            }
        });
        if ($fresh) {
            VehicleSold::dispatch($vehicle->fresh(), $message);
        }

        return $vehicle;
    }

    /**
     * Продажи не было: покупатель отказался или её записали по ошибке. Дни снова считаются страховой по обычной
     * ставке (`Accrual::buyerFrom` без `sold_at` молчит), незанятая заявка на выдачу снимается.
     */
    public function clear(Vehicle $vehicle, ?User $by, string $reason): void
    {
        Nav::forgetStaffCounts();
        DB::transaction(function () use ($vehicle, $by, $reason) {
            // Контрагент-покупатель остаётся, если ему уже выставляли счета: иначе его неоплаченное перестало бы
            // считаться долгом покупателя (`Ledger::buyerDebt` ищет по контрагенту) и показывалось бы вендорским.
            $vehicle->update(['sold_at' => null, 'sold_message_id' => null, 'pickup_name' => null, 'pickup_phone' => null, 'buyer_free_until' => null,
                'buyer_party_id' => self::billed($vehicle) ? $vehicle->buyer_party_id : null]);
            $vehicle->requests()->where('type', RequestType::Release)->whereIn('state', [RequestState::New, RequestState::Scheduled, RequestState::InProgress])
                ->update(['state' => RequestState::Cancelled, 'done_at' => now(), 'done_by' => $by?->id, 'cancel_reason' => $reason]);
            // Пропуск прежнего покупателя гаснет: новый заполнит анкету по той же ссылке.
            $vehicle->revokePass($reason);
        });
    }

    /** Контрагенту-покупателю этой ТС уже выставляли счета — он больше не «черновик», его нельзя ни стереть, ни переписать. */
    private static function billed(Vehicle $vehicle): bool
    {
        return $vehicle->buyer_party_id && $vehicle->invoices()->where('party_id', $vehicle->buyer_party_id)->exists();
    }
}
