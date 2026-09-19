<?php

namespace App\Http\Park;

use App\Http\Admin\CandidateController;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Park\Actions\PromoteCandidate;
use App\Park\Request as ParkRequest;
use Illuminate\Http\Request;

/** «Из писем» на стоянке: тот же экран, «Завести» — ТС «ожидается» и заявка на приём или эвакуацию. */
class ParkCandidateController extends CandidateController
{
    public function __construct()
    {
        parent::__construct(Scope::Park, '/requests/from-mail', '/mail');
    }

    public function promote(Request $request, Candidate $candidate)
    {
        abort_if($candidate->state === CandidateState::Promoted || $candidate->scope !== Scope::Park, 404);
        $result = app(PromoteCandidate::class)($candidate, $request->user());
        if ($result instanceof ParkRequest) {
            return redirect("/requests/{$result->id}")->with('toast', 'Заявка заведена, фото подтягиваются');
        }

        return redirect("/cars/{$result->id}")->with('toast', 'Письма привязаны к ТС');
    }
}
