<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Письма о хранении → ТС и заявка заводятся формой `/requests/new?candidate=` с полями из письма;
 * здесь — что после этого: кандидат помечен, кадр карточки больше не нужен, все его ветки привязаны, их файлы едут в дело.
 * Если такую ТС уже завели руками, второй не будет: `existing()` находит её по убытку, VIN, госномеру.
 */
final class PromoteCandidate
{
    public function __construct(private LinkThread $link) {}

    public function attach(Candidate $candidate, Vehicle $vehicle): void
    {
        $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $vehicle->id]);
        $candidate->clearMediaCollection('card');
        foreach ($candidate->threads() as $thread) {
            if ($thread->vehicle_id !== $vehicle->id) {
                ($this->link)($thread, $vehicle);
            }
        }
        // Письма с тем же номером, которые кандидату не достались (наши ответы, «ч.2» до разбора), тоже к ТС.
        $this->link->forVehicle($vehicle);
    }

    /** ТС с тем же номером убытка, VIN или госномером, не выданная и не отменённая. */
    public function existing(Candidate $candidate): ?Vehicle
    {
        $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vin = $candidate->value('vin') ? strtoupper((string) $candidate->value('vin')) : null;
        $plate = Candidate::plateKey($candidate->value('plate'));

        return ($candidate->code ? $live()->where('ref_key', Vehicle::keyFor($candidate->code))->first() : null)
            ?? ($vin ? $live()->where('vin', $vin)->first() : null)
            ?? ($plate ? $live()->where('plate', $plate)->first() : null);
    }
}
