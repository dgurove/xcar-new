<?php

namespace App\Http\Cabinet;

use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\OfferState;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Outcome;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    public function index(Request $request)
    {
        $deals = Deal::with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'openRequirement'])
            ->where('buyer_id', $request->user()->id)
            ->orderByRaw("case when state = 'active' then 0 else 1 end")->latest()->paginate(30);

        return view('cabinet.deals.index', ['deals' => $deals]);
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'openRequirement.media', 'requirements']);
        $position = $deal->offer->position(Track::Sale);
        // Лестница шагов: тупики срыва не показываем, пока сделка в них не встала.
        $blocks = $position ? $position->stage->workflow->blocks()->with('stages')->get()->filter(fn ($b) => $b->id === $position->stage->block_id
            || $b->stages->contains(fn ($s) => ! in_array($s->offer_state, [OfferState::Cancelled, OfferState::Archived], true)))->values() : collect();
        $steps = $deal->offer->events()->where('type', \App\Offers\OfferEventType::StageEntered)->where('created_at', '>=', $deal->created_at)->oldest()->get()
            ->filter(fn ($e) => ($e->payload['track'] ?? 'sale') === 'sale')
            ->map(fn ($e) => ['at' => $e->created_at, 'block' => $e->payload['block'] ?? null])
            ->filter(fn ($s) => $s['block'])
            ->values();

        return view('cabinet.deals.show', [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? $position?->stage->exitsFor(\App\Workflow\Actor::Manager) : collect(),
        ]);
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $exit = Outcome::findOrFail($request->validate(['exit' => ['required', 'integer']])['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        return redirect("/lk/sdelki/{$deal->id}")->with('toast', $exit->label);
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
