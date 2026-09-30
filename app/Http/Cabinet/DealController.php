<?php

namespace App\Http\Cabinet;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $deals = Deal::with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'openRequirement'])
            ->where('buyer_id', $me->id)
            ->orderByRaw("case when state = 'active' then 0 else 1 end")->latest()->paginate(30);

        // Подтверждения — на том же экране: ждущие решения — будущие сделки, отклонённые и отозванные —
        // короткая история внизу; принятые уже стоят сделками.
        $bids = fn () => Bid::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $me->id)->latest();
        $pending = $bids()->where('state', BidState::Active)->get();
        $lost = $bids()->whereIn('state', [BidState::Declined, BidState::Withdrawn])->limit(10)->get();

        return view('cabinet.deals.index', ['deals' => $deals, 'pending' => $pending, 'lost' => $lost]);
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'openRequirement.media', 'requirements']);
        $position = $deal->offer->position(Track::Sale);
        // Журнал с начала сделки, с учётом откатов — тот же, что путь в CRM.
        $steps = Path::journal($deal->offer, Track::Sale, $deal->created_at);
        $blocks = $position ? Path::ladder($position->stage, $steps->pluck('block')->all()) : collect();

        return view('cabinet.deals.show', [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? $position?->stage->exitsFor(Actor::Manager) : collect(),
            'invoices' => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->orderBy('id')->get(),
        ]);
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $exit = Outcome::findOrFail($request->validate(['exit' => ['required', 'integer']])['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        return redirect("/deals/{$deal->id}")->with('toast', $exit->label);
    }

    public function upload(Request $request, Deal $deal, PhotoIngest $ingest)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $request->validate(['file' => ['required', 'file', 'max:30720']]);
        $file = $request->file('file');
        try {
            if (str_starts_with((string) $file->getMimeType(), 'image/')) {
                $ingest->fromUpload($requirement, 'files', $file);
            } else {
                $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $requirement->addMedia($file)->usingFileName((trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name), '-.') ?: 'dokument').'.'.strtolower($file->getClientOriginalExtension()))->toMediaCollection('files');
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement->fresh()]);
    }

    public function removeFile(Request $request, Deal $deal, Media $media)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        abort_unless($media->model_id === $requirement->id && $media->model_type === $requirement::class, 404);
        $media->delete();

        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement->fresh()]);
    }
}
