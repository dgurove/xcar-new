<?php

namespace App\Http\Cabinet;

use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actor;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $steps = $deal->offer->events()->where('type', OfferEventType::StageEntered)->where('created_at', '>=', $deal->created_at)->oldest()->get()
            ->filter(fn ($e) => ($e->payload['track'] ?? 'sale') === 'sale')
            ->map(fn ($e) => ['at' => $e->created_at, 'block' => $e->payload['block'] ?? null])
            ->filter(fn ($s) => $s['block'])
            ->values();
        $blocks = $position ? $this->ladder($position->stage, $steps->pluck('block')->all()) : collect();

        return view('cabinet.deals.show', [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? $position?->stage->exitsFor(Actor::Manager) : collect(),
        ]);
    }

    /**
     * Лестница: позади — где сделка была по журналу, текущий блок, впереди —
     * пока путь однозначен. Развилка её обрывает: у маршрута с двумя ветками
     * покупки менеджер увидел бы обе, включая ту, от которой отказался.
     * Тупики срыва и возвраты назад впереди не считаются. Блоки сравниваются
     * по имени: у двух веток покупки блок «Согласуем с поставщиком» свой.
     */
    private function ladder(Stage $stage, array $passedNames): Collection
    {
        $all = $stage->workflow->blocks()->with('stages.exits.to.block')->get();
        $current = $all->firstWhere('id', $stage->block_id);
        $behind = collect($passedNames)->map(fn ($name) => $all->firstWhere('name', $name))->filter()
            ->reject(fn (Block $b) => $b->name === $current->name)->unique('name')->values();
        $ladder = $behind->push($current);
        $seen = $ladder->pluck('id')->all();
        while (true) {
            $next = $current->nextBlocks()->reject(fn (Block $b) => $b->isDeadEnd() || in_array($b->id, $seen, true));
            if ($next->count() !== 1) {
                return $ladder;
            }
            $current = $next->first();
            $seen[] = $current->id;
            $ladder->push($current);
        }
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $exit = Outcome::findOrFail($request->validate(['exit' => ['required', 'integer']])['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        return redirect("/account/deals/{$deal->id}")->with('toast', $exit->label);
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
