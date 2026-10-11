<?php

namespace App\Http\Park;

use App\Cars\Category;
use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Mail\Scope as MailScope;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\Intake;
use App\Park\Actions\MarkSold;
use App\Park\Actions\PromoteCandidate;
use App\Park\Actions\RefuseRelease;
use App\Park\Actions\RegisterFromLetters;
use App\Park\Actions\Release;
use App\Park\Actions\StoreByLetters;
use App\Park\Actions\UpdateVehicle;
use App\Park\Inspection;
use App\Park\ReleasedTo;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Detail;
use App\Support\Docs;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RequestController
{
    /** По умолчанию — новые сверху по дате письма, из которого заявка, без письма — по дате заявки (владелец 07.10.2026). */
    public const SORTS = ['fresh' => ['Дата письма', 'desc'], 'type' => ['Тип', 'asc']];

    public const SORT = '-fresh';

    /** Главная стоянки и список заявок: пресеты-типы (приём, выдача), поиск по ТС, вендор, площадка; три вида с карточкой строки. */
    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($req = ParkRequest::find($key)) && $req->vehicle ? app(VehicleController::class)->detail($request, $req->vehicle, $req) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $vehicle = fn (string $col) => "(select v.{$col} from park_vehicles v where v.id = park_requests.vehicle_id)";
        $facets = Facets::for($request, 'park-requests',
            Common::vendor($vehicle('vendor_id')),
            Common::yard('coalesce(park_requests.yard_id, '.$vehicle('yard_id').')'),
            Common::category($vehicle('category'), 'Без типа'),
        );
        ListPrefs::sync($request, 'park-requests', keep: $facets->keys());
        // По умолчанию — приём (11.10.2026, владелец: «по умолчанию в заявках — заявки на приём, а не все»); «Все» — ?preset=all.
        $type = $request->query('preset', RequestType::Intake->value);
        $qs = trim((string) $request->query('q'));
        // Счётчики пилюль — с выбранными чипами; лупа — по всем открытым заявкам, мимо пилюли и чипов.
        $filters = fn ($q) => $facets->applyTo($q)
            ->when($qs !== '', fn ($w) => $w->whereHas('vehicle', fn ($v) => $v->search($qs)));
        $q = (ParkRequest::with(['vehicle' => fn ($v) => $v->withCount('threads'), 'vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.media', 'vehicle.yard', 'yard', 'letter', 'creator']));
        $q->whereIn('state', RequestState::open());
        if ($qs !== '') {
            $filters($q);
        } elseif ($t = RequestType::tryFrom($type)) {
            $q->where('type', $t);
        }
        $facets->apply($q);
        $sort = Sort::from($request->query('sort'), self::SORTS, self::SORT);
        $dir = $sort->dir();
        match ($sort->key) {
            'fresh' => $q->orderByRaw("coalesce(letter_at, created_at) {$dir}")->orderBy('id', $dir),
            default => $q->orderBy('type', $dir)->orderByRaw('coalesce(letter_at, created_at) desc'),
        };

        $open = $filters(ParkRequest::query())->whereIn('state', RequestState::open())->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $counts = $open->all() + ['all' => $open->sum()];
        // Пилюля есть, пока есть заявки: «Выдача» появляется от первой такой и исчезает с последней.
        // Выбранная остаётся и с нулём, иначе активная пилюля исчезала бы из ряда вместе со списком.
        $presets = collect(['all' => 'Все'] + collect(RequestType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all())
            ->filter(fn ($label, $key) => $key === 'all' || $key === $type || ! empty($counts[$key]))->all();
        $paginator = ListView::paginate($request, $q);
        $data = [
            'requests' => $paginator,
            'sort' => $sort,
            'view' => ListView::pick($request, $paginator->total()),
            // Заявки, которые завела почта и дочитывают документы: силуэт, ход, проявление (`arrive_controller`).
            'arrive' => AutoScan::states($paginator->getCollection()->pluck('vehicle')->filter()),
        ];
        // Лента просит только список: тот же кусок, что рисует страницу.
        if ($request->header('X-List')) {
            return response()->view('park.requests.list', $data);
        }

        return view('park.requests.index', $data + [
            'preset' => $type,
            'presets' => $presets,
            'counts' => $counts,
            'q' => $qs,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    /**
     * Форма заявки. С `?candidate=` поля предзаполнены из письма, письма кандидата — рядом с формой:
     * сотрудник сверяет и сохраняет. Если такую ТС уже завели руками — форма открывается на неё.
     */
    public function create(Request $request, PromoteCandidate $promote, StoreByLetters $store)
    {
        // Руками заводится только приём новой ТС.
        $type = RequestType::Intake;
        $vehicle = $request->query('car') ? Vehicle::find($request->query('car')) : null;
        $candidate = $request->query('candidate') ? Candidate::where('scope', MailScope::Park)->where('state', '!=', CandidateState::Promoted)->with(['messages.attachments', 'messages.addresses', 'messages.author'])->find($request->query('candidate')) : null;
        $prefill = [];
        if ($candidate) {
            // Такую ТС уже завели (редкость: письма о заведённой цепочку не начинают) — в форме она показывается целиком.
            // «Это она» у строки дубля (twins) — `?car=`: письма идут к выбранной ТС.
            $vehicle = ($vehicle ?? $promote->existing($candidate))?->loadCount('threads')->load(['brand', 'model', 'vendor', 'yard', 'media', 'requests']);
            // Письма этой цепочки к ТС ещё не привязаны, но они вот, на экране: пилюля «Писем нет» тут соврала бы.
            $vehicle?->setAttribute('threads_count', $vehicle->threads_count + $candidate->threads()->count());
            [$brand, $model] = $candidate->cars();
            // Поля ТС — те же, по которым цепочка заводится сама (`StoreByLetters`); тут они на глазах у сотрудника.
            $prefill = $candidate->vehicleData() + [
                'brand' => $brand, 'model' => $model,
            ];
            // По письмам ТС уже принята или продана: этапы менеджеру на проверку, заведётся стоящей.
            if ($candidate->stage !== CandidateStage::Intake) {
                $stored = $candidate->stageOf(CandidateStage::Stored);
                $sold = $candidate->stageOf(CandidateStage::Sold);
                $prefill['stages'] = [
                    'stored' => true, 'accepted_at' => ($stored['at'] ?? null) ? substr($stored['at'], 0, 10) : null, 'stored_title' => $stored['title'] ?? 'Принята',
                    // Парковку знает тот, кто написал: у питерского эксперта вендора машины стоят в Петербурге.
                    'yard_id' => $store->yard($candidate)?->id,
                    'sold' => (bool) $sold, 'sold_at' => ($sold['at'] ?? null) ? substr($sold['at'], 0, 10) : null,
                    'pickup_name' => $sold['name'] ?? null, 'pickup_phone' => $sold['phone'] ?? null,
                ];
            }
        }

        // Письмо-заявка — то, с которого цепочка началась: оно раскрыто в ленте, из него же берётся скан.
        $letter = $candidate?->message ?? $candidate?->messages->first(fn ($m) => ! $m->isOurs()) ?? $candidate?->messages->first(fn ($m) => $m->direction === Direction::In);
        // Документы для шторки: письмо-заявка, файлы писем вендора (сначала этого письма), фото. Скан заявки
        // страховой (у Альфы Москва в нём марка, модель, VIN, год, цвет и стоимость) открывается сам.
        $docs = [];
        if ($candidate) {
            // Входящие: заявку сотрудник бывает что пересылает со своего ящика — скан в ней тот же.
            $vendorLetters = $candidate->messages->filter(fn ($m) => $m->direction === Direction::In)->sortByDesc(fn ($m) => $m->id === $letter?->id ? 1 : 0);
            ['docs' => $files, 'photos' => $photos] = Docs::fromLetters($vendorLetters, '/mail');
            $docs = array_values(array_filter([$letter ? Docs::letter($letter, '/mail') : null, ...$files, Docs::photos($photos)]));
        }

        return view('park.requests.create', [
            'type' => $type,
            'letter' => $letter,
            'docs' => $docs,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'vendors' => Vendor::onPark()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'vehicle' => $vehicle,
            'candidate' => $candidate,
            'p' => $prefill,
            'messages' => $candidate?->messages ?? collect(),
            'yardRows' => Yard::where('is_active', true)->get()->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
        ]);
    }

    public function store(Request $request, CreateRequest $create, PromoteCandidate $promote, LinkThread $link, RegisterFromLetters $register, MarkSold $markSold)
    {
        VehicleFields::clean($request);
        $data = $request->validate([
            'stages' => ['nullable', 'array'], 'stages.stored' => ['nullable', 'boolean'], 'stages.accepted_at' => ['nullable', 'date'],
            'stages.yard_id' => ['nullable', 'exists:park_yards,id'], 'stages.spot' => ['nullable', 'string', 'max:10'],
            'stages.sold' => ['nullable', 'boolean'], 'stages.sold_at' => ['nullable', 'date'], 'stages.pickup_name' => ['nullable', 'string', 'max:80'], 'stages.pickup_phone' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::enum(RequestType::class)],
            'vehicle_id' => ['nullable', 'exists:park_vehicles,id'],
            'candidate_id' => ['nullable', 'exists:mail_candidates,id'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'flags' => ['nullable', 'array'], 'flags.*' => ['string', 'max:20'],
            'docs_required' => ['nullable', 'array'], 'docs_required.*' => ['string', 'max:20'],
        ] + VehicleFields::rules(identity: ! $request->filled('vehicle_id')));  // у существующей ТС поля не спрашиваются («Выдать» шлёт только тип и ТС)
        $type = RequestType::from($data['type']);
        $vehicle = ! empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        if ($type !== RequestType::Intake && ! $vehicle) {
            return back()->withInput()->withErrors(['vehicle_id' => 'Выберите ТС']);
        }
        $candidate = ! empty($data['candidate_id']) ? Candidate::where('scope', MailScope::Park)->find($data['candidate_id']) : null;
        if ($candidate) {
            $data['thread_id'] = $candidate->thread_id;
            // Список заявок — по дате письма, из которого заявка (`letter_at`).
            $letter = $candidate->message ?? $candidate->messages()->orderBy('date_at')->first();
            $data['letter_at'] = $letter?->date_at;
            $data['letter_id'] = $letter?->id;
            // Кандидата уже завели (двойное нажатие, вторая вкладка) — второй ТС не будет.
            if ($candidate->state === CandidateState::Promoted && $candidate->vehicle_id) {
                return redirect("/cars/{$candidate->vehicle_id}")->with('toast', 'Уже заведена');
            }
            $vehicle ??= $promote->existing($candidate);
            // ТС уже завели: заявка на приём нужна, только пока машины нет на парковке — иначе письма просто идут к делу.
            if ($vehicle && ! ($candidate->stage === CandidateStage::Intake && RequestType::Intake->allowedFor($vehicle->state))) {
                $promote->attach($candidate, $vehicle);
                // Письмо о продаже цепочка уже разобрала: без этого ТС так и стояла бы «на хранении», без заявки на выдачу.
                $soldStage = $candidate->stageOf(CandidateStage::Sold);
                if ($soldStage && ! $vehicle->sold_at) {
                    $markSold($vehicle, $request->user(), Carbon::parse($soldStage['at'] ?? now()), $soldStage['name'] ?? null, $soldStage['phone'] ?? null,
                        Message::find($soldStage['message_id'] ?? null));
                }

                return redirect("/cars/{$vehicle->id}")->with('toast', $soldStage && $vehicle->state === VehicleState::Stored ? 'Письма привязаны, ждёт выдачи' : 'Письма привязаны к ТС');
            }
            // Менеджер подтвердил: по письмам ТС уже принята (и, может быть, продана) — заводится стоящей, без заявки на приём.
            if (! $vehicle && ! empty($data['stages']['stored'])) {
                $vehicle = $register($request->user(), $candidate, VehicleFields::only($data) + ['flags' => $data['flags'] ?? [], 'docs_required' => $data['docs_required'] ?? []], $data['stages']);
                $promote->attach($candidate, $vehicle);

                return redirect("/cars/{$vehicle->id}")->with('toast', ! empty($data['stages']['sold']) ? 'Заведена, ждёт выдачи' : 'Заведена стоящей');
            }
        }
        $req = $create($request->user(), $type, $vehicle, $data);
        if ($candidate && $candidate->state !== CandidateState::Promoted) {
            $promote->attach($candidate, $req->vehicle);
        } elseif (! $vehicle) {
            // ТС завели руками — письма с её номером, VIN или госномером уже могли прийти, а о ней самой могла ждать цепочка.
            $link->forVehicle($req->vehicle);
            $promote->forVehicle($req->vehicle);
        }

        // «Завести и ещё» — пачкой подряд: сразу пустая форма следующей. Адрес другой (`after`), иначе Turbo освежит ту же
        // страницу морфом и оставит в полях прошлую ТС.
        if ($request->input('then') === 'next') {
            return redirect('/requests/new?after='.$req->vehicle_id)->with('toast', 'Заявка заведена');
        }

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Заявка заведена');
    }

    /** Страницы заявки нет — заявка живёт блоком в деле ТС; старые ссылки (уведомления, закладки) ведут туда. */
    public function show(Request $http, ParkRequest $req)
    {
        return redirect("/cars/{$req->vehicle_id}", 301);
    }

    /**
     * Поля ТС приходят той же формой, что и этап заявки: одна кнопка сохраняет всё.
     * Правит их только `park.manage` — «только приёмке» форма показывает их чипами.
     */
    private function saveVehicle(Request $request, Vehicle $vehicle): void
    {
        if (! $request->user()->canManagePark() || ! $request->has('vehicle_form')) {
            return;
        }
        VehicleFields::clean($request);
        $data = VehicleFields::only($request->validate(VehicleFields::rules()));
        app(UpdateVehicle::class)($vehicle, $data, $request->user());
        $vehicle->refresh();
    }

    public function intake(Request $request, ParkRequest $req, Intake $intake)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16'], 'accepted_at' => ['nullable', 'date']] + self::inspectionRules());
        $this->saveVehicle($request, $req->vehicle);
        $intake($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, $data, $req, $data['spot'] ?? null);

        // Из окна заявки (фрейм карточки) — строка уходит, окно к следующей заявке; из дела — дело, где шаг «Отчёт вендору»
        // ждёт с черновиком (акт и фото), отправит сотрудник, проверив.
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('detail-advance', true)->with('toast', 'Принята');
        }

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Принята');
    }

    /** Выдача: когда, кому, ключи и документы в акт, подпись; дальше редактор письма вендору с актом выдачи. */
    public function release(Request $request, ParkRequest $req, Release $release)
    {
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'to' => ['nullable', Rule::enum(ReleasedTo::class)],
            // Выдача по QR: код из сканера или причина выдачи без него (от 20 знаков — отписку «так надо» не примем).
            'pass' => ['nullable', 'string', 'max:200'], 'pass_confirm' => ['nullable', 'boolean'],
            'without_qr_reason' => [Rule::requiredIf($request->boolean('without_qr')), 'nullable', 'string', 'min:20', 'max:500'],
        ] + self::inspectionRules(), [
            'without_qr_reason.required' => 'Напишите, почему выдаёте без QR',
            'without_qr_reason.min' => 'Опишите причину подробнее, от 20 знаков',
        ]);
        $at = isset($data['released_at']) ? Carbon::parse($data['released_at']) : null;
        $this->saveVehicle($request, $req->vehicle);
        $qr = $request->boolean('without_qr') ? ['without' => $data['without_qr_reason']] : ['pass' => $data['pass'] ?? null, 'confirm' => $request->boolean('pass_confirm')];
        $release($req->vehicle, $request->user(), $at, null, ReleasedTo::tryFrom($data['to'] ?? '') ?? ($req->vehicle->sold_at ? ReleasedTo::Buyer : null), $data, $req, $request->boolean('force') && $request->user()->canManagePark(), $request->boolean('cash'), $qr);

        // Из окна заявки — строка уходит, окно к следующей выдаче; отчёт вендору ждёт шагом в деле.
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('detail-advance', true)->with('toast', 'Выдана');
        }

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Выдана');
    }

    /** Покупатель приехал и не взял: продажа снимается, ТС остаётся стоять, вендору уходит письмо об отказе. */
    public function refuse(Request $request, ParkRequest $req, RefuseRelease $refuse)
    {
        abort_unless($request->user()->canManagePark(), 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $refuse($req->vehicle, $request->user(), $reason);
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('toast', 'Отказ записан');
        }

        return redirect(self::withReport($req->vehicle->fresh(), 'refusal'))->with('toast', 'Отказ записан, письмо вендору готово');
    }

    /** Адрес дела с окном писем поверх, где ждёт черновик вендору с актом и фото; писать некому — просто дело. */
    public static function withReport(Vehicle $vehicle, string $act): string
    {
        $report = $vehicle->reportUrl($act, "/cars/{$vehicle->id}");

        return "/cars/{$vehicle->id}".($report ? '?window='.urlencode($report) : '');
    }

    /** @return array<string, list<mixed>> что спрашивает приём и выдача, пока модуля осмотра нет: ключи и документы */
    public static function inspectionRules(): array
    {
        return [
            'keys_count' => ['nullable', 'integer', 'between:0,9'],
            'docs' => ['nullable', 'array'], 'docs.*' => [Rule::in(array_keys(Inspection::DOCS))],
        ];
    }
}
