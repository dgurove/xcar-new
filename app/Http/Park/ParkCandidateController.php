<?php

namespace App\Http\Park;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Park\Actions\PromoteCandidate;
use Illuminate\Http\Request;

class ParkCandidateController
{
    public function index(Request $request)
    {
        $preset = $request->query('preset', 'new');
        $candidates = Candidate::with(['message.attachments', 'thread', 'vehicle'])->where('scope', Scope::Park)
            ->where('state', CandidateState::tryFrom($preset) ?? CandidateState::New)->latest()->paginate(30)->withQueryString();

        return view('park.requests.candidates', ['candidates' => $candidates, 'preset' => $preset,
            'counts' => ['new' => Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->count()]]);
    }

    public function promote(Request $request, Candidate $candidate, PromoteCandidate $promote)
    {
        abort_if($candidate->state === CandidateState::Promoted || $candidate->scope !== Scope::Park, 404);
        $req = $promote($candidate, $request->user());

        return redirect("/requests/{$req->id}")->with('toast', 'Заявка заведена, фото подтягиваются');
    }

    public function reject(Candidate $candidate)
    {
        $candidate->update(['state' => $candidate->state === CandidateState::Rejected ? CandidateState::New : CandidateState::Rejected]);

        return back();
    }
}
