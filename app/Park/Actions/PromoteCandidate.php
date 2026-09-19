<?php

namespace App\Park\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/** Письмо о хранении → машина «ожидается» и заявка на приём, ветка привязана к машине — её файлы едут в карточку. */
final class PromoteCandidate
{
    public function __construct(private CreateRequest $create, private LinkThread $link) {}

    public function __invoke(Candidate $candidate, User $by): Request
    {
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
            if ($candidate->thread) {
                ($this->link)($candidate->thread, Vehicle::find($request->vehicle_id));
            }

            return $request;
        });

        return $request;
    }
}
