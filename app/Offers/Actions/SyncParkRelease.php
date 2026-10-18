<?php

namespace App\Offers\Actions;

use App\Notifications\ParkNotice;
use App\Notifications\ParkPassNotice;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\MarkSold;
use App\Park\EventType;
use App\Park\Pass;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Выдача с нашей парковки по сделке XCar — одна дверь (18.10.2026, владелец: «полноценно: покупатель нашего менеджера,
 * сам менеджер, в гараж»). Идемпотентно приводит парковку к сделке:
 * — сделке можно забирать (`Deal::releasable`), машина стоит у нас или едет к нам: продажа на парковке на получателя
 *   (`MarkSold`, письма страховой не нужно), заявка на выдачу с `deal_id` (у той, что едет, — при приёме) и QR-пропуск
 *   (`Park\Pass` с `deal_id`, подтверждён самой сделкой); получатель или время сменились — заявка и продажа правятся,
 *   прежний пропуск гаснет, новый — новый код;
 * — сделка кончилась или отменена: пропуск сделки гаснет, продажу, что ставила сделка, парковка снимает (`MarkSold::clear`).
 * Продажу из письма страховой («продано, заберёт …») сделка не перетирает: там решают люди.
 */
final class SyncParkRelease
{
    public function __construct(private MarkSold $sold, private CreateRequest $create) {}

    public function __invoke(Deal $deal, ?User $by = null): void
    {
        $offer = $deal->offer;
        $vehicle = $offer?->parkVehicle()->first();
        if (! $vehicle) {
            return;
        }
        // Отменили — выдача снимается; закончилась (гаражная закрыта, машина ещё у нас) — пропуск живёт до выдачи.
        if ($deal->state === \App\Offers\DealState::Cancelled) {
            $this->withdraw($deal, $vehicle, $by, 'Сделка отменена');

            return;
        }
        if (! $deal->isActive()) {
            return;
        }
        if (! in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true) || ! $deal->releasable()) {
            return;
        }
        $receiver = $deal->receiver();
        $pass = $vehicle->passes()->where('deal_id', $deal->id)->whereNull('revoked_at')->whereNull('used_at')->first();
        // Продано по письму страховой кому-то ещё, а нашего пропуска нет — не трогаем: решают люди.
        if (! $receiver || ($vehicle->sold_at && $vehicle->sold_message_id && ! $pass)) {
            return;
        }
        $phone = Phone::normalize($receiver->phone) ? Phone::format(Phone::normalize($receiver->phone)) : $receiver->phone;
        if ($pass && $receiver->matches($pass->name, $pass->phone ?: null, $pass->planned_at)) {
            $this->ensureRequest($deal, $vehicle->fresh(), $receiver->name, $phone, $by);

            return;
        }
        $first = ! $pass;
        DB::transaction(function () use ($deal, $vehicle, $receiver, $phone, $by, $pass) {
            // Анкета страховой (или прежний пропуск сделки) гаснет: на воротах — один живой пропуск.
            $vehicle->revokePass($pass ? 'Сменился получатель' : 'Выдача по сделке XCar');
            ($this->sold)($vehicle, $by, now(), $receiver->name, $phone);
            Pass::create([
                'vehicle_id' => $vehicle->id, 'code' => self::code(), 'name' => $receiver->name, 'phone' => (string) $phone,
                'submitted_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $by?->id,
                'confirm_note' => 'по сделке № '.$deal->offer->number, 'deal_id' => $deal->id, 'planned_at' => $receiver->at,
            ]);
            $this->ensureRequest($deal, $vehicle->fresh(), $receiver->name, $phone, $by);
            $who = $receiver->name.($receiver->at ? ', приедет '.$receiver->at->translatedFormat('j M, H:i') : '');
            $vehicle->log(EventType::Note, $by, ['text' => ($pass ? 'Сменился получатель по сделке XCar № ' : 'На выдачу по сделке XCar № ').$deal->offer->number.': '.$who]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => ($pass ? 'Парковке: сменился получатель, ' : 'Парковке: на выдачу, заберёт ').$who]);
        });
        DB::afterCommit(function () use ($deal, $vehicle, $first, $by) {
            $vehicle = $vehicle->fresh();
            // Первый пропуск — менеджеру «можно забирать с парковки» (кроме того, кто сам и нажал); смена — управляющим.
            if ($first && $deal->buyer && ! $deal->buyer->is($by)) {
                $deal->buyer->notify(new ParkPassNotice($deal, $vehicle));
            }
            if (! $first) {
                Notification::send(app(\App\Notifications\Notify::class)->parkManagersOf($vehicle->city_id), ParkNotice::receiverChanged($vehicle));
            }
        });
    }

    /** Сделка кончилась: её пропуск гаснет, продажу, что ставила она (не письмо страховой), парковка снимает. */
    public function withdraw(Deal $deal, Vehicle $vehicle, ?User $by, string $reason): void
    {
        $pass = $vehicle->passes()->where('deal_id', $deal->id)->whereNull('revoked_at')->whereNull('used_at')->first();
        if (! $pass) {
            return;
        }
        $pass->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
        if ($vehicle->sold_at && ! $vehicle->sold_message_id && in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
            $this->sold->clear($vehicle, $by, $reason);
        }
        $vehicle->log(EventType::Note, $by, ['text' => 'Выдача по сделке XCar № '.$deal->offer?->number.' снята: '.mb_strtolower($reason)]);
    }

    /** Заявка на выдачу по сделке: есть — с её получателем и сделкой, нет и машина стоит — заводится. */
    private function ensureRequest(Deal $deal, Vehicle $vehicle, string $name, ?string $phone, ?User $by): void
    {
        $open = $vehicle->requests()->where('type', RequestType::Release)->whereIn('state', RequestState::open())->first();
        if ($open) {
            $open->update(['contact_name' => $name, 'contact_phone' => $phone, 'deal_id' => $deal->id]);
        } elseif ($vehicle->state === VehicleState::Stored) {
            ($this->create)($by, RequestType::Release, $vehicle, ['contact_name' => $name, 'contact_phone' => $phone, 'deal_id' => $deal->id]);
        }
    }

    private static function code(): string
    {
        do {
            $code = Pass::freshCode();
        } while (Pass::where('code', $code)->exists());

        return $code;
    }
}
