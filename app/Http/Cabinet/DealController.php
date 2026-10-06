<?php

namespace App\Http\Cabinet;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Actions\PickUp;
use App\Offers\Deal;
use App\Offers\Handover;
use App\Offers\InsurerReplies;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    /** Внутри разделов «В работе» и «Закончены»: новые сверху (где ждут ответа — первыми, как было); сумма — выбором. */
    public const SORTS = ['fresh' => ['Дата', 'desc'], 'amount' => ['Сумма', 'desc']];

    public function index(Request $request)
    {
        $me = $request->user();
        ListPrefs::sync($request, 'deals', view: false, keep: ['sort']);
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        // Гаражные сделки живут в «Гараже» (решение владельца 03.10.2026), здесь — только ждущее решения «В гараж».
        $deals = Deal::with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'openRequirement'])
            ->where('buyer_id', $me->id)->whereNull('garage_payer')
            ->orderByRaw("case when state = 'active' then 0 else 1 end")
            ->when($sort->key === 'amount', fn ($d) => $d->orderByRaw("amount {$sort->dir()} nulls last"))->orderBy('created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('id')->paginate(30);

        // Подтверждения — на том же экране: ждущие решения — будущие сделки, отклонённые и отозванные —
        // короткая история внизу; принятые уже стоят сделками.
        $bids = fn () => Bid::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $me->id)->latest();
        $pending = $bids()->where('state', BidState::Active)->get();
        $lost = $bids()->whereIn('state', [BidState::Declined, BidState::Withdrawn])->limit(10)->get();

        return view('cabinet.deals.index', ['deals' => $deals, 'pending' => $pending, 'lost' => $lost, 'sort' => $sort]);
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        // Гаражная сделка живёт в гараже: старые ссылки ленты и Telegram ведут туда же.
        if ($deal->isGarage() && $deal->garageCar()->exists()) {
            return redirect($deal->href());
        }

        return view('cabinet.deals.show', self::stepData($deal));
    }

    /** Карточка чата по сделке — фрейм перечитывает её по новому сообщению. */
    public function chat(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->offer->chatOpenFor($request->user()), 404);

        return view('cabinet.deals.chat-card', ['deal' => $deal, 'offer' => $deal->offer]);
    }

    /** Сделка с текущим шагом, путём и счетами — для её страницы и карточки машины в гараже. */
    public static function stepData(Deal $deal): array
    {
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'offer.settlement', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'openRequirement.media', 'requirements']);
        $position = $deal->offer->position(Track::Sale);
        // Журнал с начала сделки, с учётом откатов — тот же, что путь в CRM.
        $steps = Path::journal($deal->offer, Track::Sale, $deal->created_at);
        // Менеджеру путь целиком: на развилке — главной дорогой (`guess`), а не обрывом на текущем шаге.
        $blocks = $position ? Path::ladder($position->stage, $steps->pluck('block')->all(), $deal, guess: true) : collect();

        return [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? $position?->stage->exitsFor(Actor::Manager, $deal) : collect(),
            'handover' => Handover::for($deal),
            // Ответы страховой по сделке — текстом до подписи (05.10.2026), новые сверху.
            'replies' => InsurerReplies::for($deal),
            // Документы, что пришли с ответом (вложения, облако по ссылке, `ShareReplyFiles`), — под этим ответом.
            'replyFiles' => $deal->offer->papers()->filter(fn ($m) => $m->getCustomProperty('letter'))->groupBy(fn ($m) => $m->getCustomProperty('letter')),
            'invoices' => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->with('claims')->orderBy('id')->get(),
        ];
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $exit = Outcome::findOrFail($request->validate(['exit' => ['required', 'integer']])['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        // Сразу туда, где сделку смотрят (гаражная — в гараж): второй редирект съел бы тост.
        return redirect($deal->refresh()->href())->with('toast', $exit->label);
    }

    /** «Автомобиль забрал» из карточки получения — у вывоза, когда шага продажи для этого нет (`Handover::servicePick`). */
    public function picked(Request $request, Deal $deal, PickUp $pickUp)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->isActive(), 404);
        abort_unless(Handover::for($deal)->servicePick, 422);
        $pickUp($deal->offer, $request->user());

        return redirect($deal->href())->with('toast', 'Автомобиль у вас');
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

        return $this->filesStream($deal, $requirement);
    }

    public function removeFile(Request $request, Deal $deal, Media $media)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        abort_unless($media->model_id === $requirement->id && $media->model_type === $requirement::class, 404);
        $media->delete();

        return $this->filesStream($deal, $requirement);
    }

    /** Файлы шага, его кнопки (появляются с первым файлом) и галка «Подписанный договор» — одним потоком. */
    private function filesStream(Deal $deal, Requirement $requirement)
    {
        $requirement = $requirement->fresh();
        $stage = $deal->offer->position(Track::Sale)?->stage;

        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement, 'deal' => $deal,
            'exits' => $stage ? $stage->exitsFor(Actor::Manager, $deal) : collect()]);
    }
}
