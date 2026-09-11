<?php

namespace App\Park\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Park\Client;
use App\Park\Request;
use App\Park\RequestType;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Письмо о хранении → машина «ожидается» и заявка на приём, ветка привязана к машине. Фото — джобой. */
final class PromoteCandidate
{
    public function __construct(private CreateRequest $create) {}

    public function __invoke(Candidate $candidate, User $by): Request
    {
        $request = DB::transaction(function () use ($candidate, $by) {
            $v = fn (string $f) => $candidate->value($f);
            $request = ($this->create)($by, RequestType::Intake, null, [
                'ref' => $candidate->code, 'vin' => $v('vin'), 'plate' => $v('plate'), 'year' => null,
                'brand' => $v('brand'), 'model' => $v('model'), 'color' => $v('color'),
                'client_id' => Client::forSender($v('sender'))?->id,
                'thread_id' => $candidate->thread_id,
                'contact' => $v('phones') ? implode(', ', (array) $v('phones')) : null,
                'note' => $candidate->subject,
            ]);
            $candidate->update(['state' => CandidateState::Promoted, 'vehicle_id' => $request->vehicle_id]);
            $candidate->thread?->update(['vehicle_id' => $request->vehicle_id]);

            return $request;
        });
        \App\Park\Jobs\ImportCandidateMedia::dispatch($candidate->id, $request->vehicle_id);

        return $request;
    }
}
