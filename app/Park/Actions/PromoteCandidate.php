<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Письма о хранении → ТС и заявка заводятся формой `/requests/new?candidate=` с полями из письма;
 * здесь — что после этого: кандидат помечен, его кадры переезжают к ТС, все его ветки привязаны, их файлы едут в карточку.
 * Если такую ТС уже завели руками, второй не будет: `existing()` находит её по убытку, VIN, госномеру.
 */
final class PromoteCandidate
{
    public function __construct(private LinkThread $link) {}

    public function attach(Candidate $candidate, Vehicle $vehicle): void
    {
        $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $vehicle->id]);
        $candidate->moveMediaTo($vehicle);
        foreach ($candidate->threads() as $thread) {
            if ($thread->vehicle_id !== $vehicle->id) {
                ($this->link)($thread, $vehicle);
            }
        }
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
