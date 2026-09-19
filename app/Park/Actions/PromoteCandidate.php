<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Письма о хранении → машина «ожидается» и заявка на приём; все ветки кандидата привязаны к машине —
 * их файлы едут в карточку. Если такую ТС уже завели руками, второй не будет: письма привязываются к ней.
 */
final class PromoteCandidate
{
    public function __construct(private CreateRequest $create, private LinkThread $link) {}

    public function __invoke(Candidate $candidate, User $by): Request|Vehicle
    {
        if ($vehicle = $this->existing($candidate)) {
            $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $vehicle->id]);
            $this->linkAll($candidate, $vehicle);

            return $vehicle;
        }
        $request = DB::transaction(function () use ($candidate, $by) {
            $v = fn (string $f) => $candidate->value($f);
            // «На вывоз» — заявка на эвакуацию с контактом и адресом; «передача ТС» без вывоза — приём.
            $type = $v('request') === 'tow' ? RequestType::Tow : RequestType::Intake;
            $request = ($this->create)($by, $type, null, [
                'ref' => $candidate->code, 'vin' => $v('vin'), 'plate' => $v('plate'), 'year' => null,
                'brand' => $v('brand'), 'model' => $v('model'), 'color' => $v('color'),
                'category' => $v('category'),
                'vendor_id' => $v('vendor_id') ?? Vendor::forSender($v('sender'))?->id,
                'thread_id' => $candidate->thread_id,
                'contact_name' => $v('insured_name'),
                'contact_phone' => $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null,
                'from_address' => $v('location'),
                'flags' => $v('flags') ?: [],
                'docs_required' => $v('docs_required') ?: [],
                'value' => $v('value'),
                'note' => $candidate->subject,
            ]);
            $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $request->vehicle_id]);
            $this->linkAll($candidate, Vehicle::find($request->vehicle_id));

            return $request;
        });

        return $request;
    }

    private function linkAll(Candidate $candidate, Vehicle $vehicle): void
    {
        foreach ($candidate->threads() as $thread) {
            if ($thread->vehicle_id !== $vehicle->id) {
                ($this->link)($thread, $vehicle);
            }
        }
    }

    /** ТС с тем же номером убытка, VIN или госномером, не выданная и не отменённая. */
    private function existing(Candidate $candidate): ?Vehicle
    {
        $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->latest();
        $vin = $candidate->value('vin') ? strtoupper((string) $candidate->value('vin')) : null;
        $plate = Candidate::plateKey($candidate->value('plate'));

        return ($candidate->code ? $live()->where('ref_key', Vehicle::keyFor($candidate->code))->first() : null)
            ?? ($vin ? $live()->where('vin', $vin)->first() : null)
            ?? ($plate ? $live()->where('plate', $plate)->first() : null);
    }
}
