<?php

namespace App\Mail\Actions;

use App\Mail\Extraction\Keys;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Ветка ↔ машина (оффер или машина стоянки): по номерам письма (`Message::keys` — убыток из темы, VIN, госномер, как
 * их прочёл `ReadLetter`; цитаты в счёт не идут) либо руками. Одна дверь: как бы ни привязали, файлы ветки едут в
 * медиатеку машины (ImportThreadFiles).
 */
final class LinkThread
{
    /** `files: false` — привязать без импорта файлов (ТС уже выдана: из писем ничего не хранится). */
    public function __invoke(Thread $thread, Offer|Vehicle $to, bool $files = true): void
    {
        $thread->update(($to instanceof Offer ? ['offer_id' => $to->id] : ['vehicle_id' => $to->id]) + ['unlinked_at' => null]);
        if ($files) {
            ImportThreadFiles::dispatch($thread->id);
        }
    }

    /**
     * ТС завели (руками или из письма): все непривязанные ветки с её номером, VIN или госномером — к ней,
     * включая наши ответы из почтового клиента и письма «ч.2», пришедшие отдельной веткой.
     */
    public function forVehicle(Vehicle $vehicle, bool $files = true): int
    {
        $keys = Keys::ofVehicle($vehicle);
        $threads = Thread::withAnyKey($keys)->whereNull('vehicle_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->get();
        foreach ($threads as $thread) {
            $this($thread, $vehicle, $files);
        }

        return $threads->count();
    }

    public function forOffer(Offer $offer): int
    {
        $keys = Keys::ofOffer($offer);
        $threads = Thread::withAnyKey($keys)->whereNull('offer_id')->whereNull('unlinked_at')
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->get();
        foreach ($threads as $thread) {
            $this($thread, $offer);
        }

        return $threads->count();
    }

    /** Отвязать руками: ссылка обнуляется, автопривязка по номеру или VIN эту ветку больше не трогает; файлы и blobs остаются. */
    public function unlink(Thread $thread): void
    {
        $thread->update(['offer_id' => null, 'vehicle_id' => null, 'unlinked_at' => now()]);
    }

    public function auto(Message $message): Offer|Vehicle|null
    {
        $thread = $message->thread;
        if (! $thread) {
            return null;
        }
        if ($message->account->scope === Scope::Park) {
            return $this->autoPark($message, $thread);
        }
        if ($thread->offer_id || $thread->unlinked_at) {
            return null;
        }
        foreach ($this->keyed($message, 'code') as $code) {
            if ($offer = Offer::where('claim_ref_key', $code)->first()) {
                $this($thread, $offer);

                return $offer;
            }
        }
        if ($vin = $this->keyed($message, 'vin')[0] ?? null) {
            if ($offer = Offer::where('vin', $vin)->latest()->first()) {
                $this($thread, $offer);

                return $offer;
            }
        }

        return null;
    }

    /** Стоянка: ветка ↔ машина по номеру убытка, VIN или госномеру. */
    private function autoPark(Message $message, Thread $thread): ?Vehicle
    {
        if ($thread->vehicle_id || $thread->unlinked_at) {
            return null;
        }
        $fields = $message->fields();
        // Только живая ТС: письмо о выданной или отменённой — новый заезд, ему место в «Из писем».
        $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vehicle = null;
        if ($code = $fields['code']['value'] ?? null) {
            $vehicle = $live()->where('ref_key', Vehicle::keyFor($code))->first();
        }
        $vehicle ??= ($vin = $this->keyed($message, 'vin')[0] ?? null) ? $live()->where('vin', $vin)->first() : null;
        $vehicle ??= ($plate = $fields['plate']['value'] ?? null) ? $live()->where('plate', mb_strtoupper($plate))->first() : null;
        if ($vehicle) {
            $this($thread, $vehicle);
        }

        return $vehicle;
    }

    /** Номера письма одного вида (`code`, `vin`, `plate`) без приставки. @return list<string> */
    private function keyed(Message $message, string $kind): array
    {
        return array_values(array_map(fn ($k) => substr($k, strlen($kind) + 1), array_filter($message->keys(), fn ($k) => str_starts_with($k, $kind.':'))));
    }
}
