<?php

namespace App\Park\Actions;

use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Письмо с запросом осмотра по стоящей ТС (`Intent::Inspect`: «покупатель хочет осмотреть», «допустить к осмотру») —
 * заявка «Осмотр» сама (13.11.2026, Сергей с парковки: «чтоб сюда запросы на осмотр падали»). Одна открытая на ТС: повтор
 * письма ложится в ту же (номер письма обновляется). Кто просит — отправитель письма.
 */
final class InspectByLetter
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Vehicle $vehicle, Message $message): ?Request
    {
        if ($message->intent !== Intent::Inspect->value || $vehicle->state !== VehicleState::Stored || $message->isOurs()) {
            return null;
        }
        $open = $vehicle->requests()->where('type', RequestType::Inspect)->whereIn('state', RequestState::open())->first();
        if ($open) {
            $open->update(['letter_id' => $message->id, 'letter_at' => $message->date_at, 'thread_id' => $message->thread_id]);

            return $open;
        }
        try {
            $request = ($this->create)(null, RequestType::Inspect, $vehicle, [
                'thread_id' => $message->thread_id, 'letter_id' => $message->id, 'letter_at' => $message->date_at,
                'contact_name' => $message->from_name ?: $message->from_email,
            ]);
            $vehicle->log(EventType::Note, null, ['text' => 'Запросили осмотр — заявка «Осмотр» из письма']);

            return $request;
        } catch (Throwable $e) {
            Log::info('park: осмотр из письма не завёлся', ['vehicle' => $vehicle->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
