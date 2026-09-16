<?php

namespace App\Http\Admin;

use App\Mail\Actions\PromoteCandidate;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use Illuminate\Http\Request;

class CandidateController
{
    public const PRESETS = ['new' => 'Ждут', 'rejected' => 'Отклонённые', 'promoted' => 'Заведённые'];

    public function index(Request $request)
    {
        $preset = $request->query('preset', 'new');
        $candidates = Candidate::with(['message.account', 'message.attachments', 'offer'])
            ->where('scope', Scope::Offers)
            ->where('state', CandidateState::tryFrom($preset) ?? CandidateState::New)
            ->latest()->paginate(30)->withQueryString();

        return view('admin.offers.candidates', [
            'candidates' => $candidates,
            'preset' => $preset,
            'counts' => ['new' => Candidate::where('scope', Scope::Offers)->where('state', CandidateState::New)->count()],
        ]);
    }

    public function promote(Request $request, Candidate $candidate, PromoteCandidate $promote)
    {
        abort_if($candidate->state === CandidateState::Promoted, 404);
        $offer = $promote($candidate, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', 'Черновик заведён, фото подтягиваются');
    }

    public function reject(Candidate $candidate)
    {
        $candidate->update(['state' => $candidate->state === CandidateState::Rejected ? CandidateState::New : CandidateState::Rejected]);

        return back();
    }
}
