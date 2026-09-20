<?php

namespace App\Http\Park;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope as MailScope;
use App\Park\Actions\AssignRequest;
use App\Park\Actions\CloseRequest;
use App\Park\Actions\Contact;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\Intake;
use App\Park\Actions\Move;
use App\Park\Actions\PromoteCandidate;
use App\Park\Actions\RefuseRelease;
use App\Park\Actions\Release;
use App\Park\Actions\ScheduleTow;
use App\Park\Actions\StartTow;
use App\Park\Actions\UpdateVehicle;
use App\Park\Delivery;
use App\Park\Inspection;
use App\Park\ReleasedTo;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Scope;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Park\Yard;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Phone;
use App\Users\User;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RequestController
{
    public const SORTS = ['planned' => 'По сроку', 'fresh' => 'Сначала новые', 'type' => 'По типу'];

    /** Главная стоянки и список заявок: пресеты «Просрочено», «Связаться», типы и «Готовые», поиск по ТС, вендор, площадка, «Мои»; три вида с окошком строки. */
    public function index(Request $request)
    {
        ListPrefs::sync($request, 'park-requests');
        $type = $request->query('preset', 'all');
        $qs = trim((string) $request->query('q'));
        $filters = fn ($q) => $q
            ->when($request->query('vendor'), fn ($w, $id) => $w->whereHas('vehicle', fn ($v) => $v->where('vendor_id', $id)))
            ->when($request->query('yard'), fn ($w, $id) => $w->where(fn ($s) => $s->where('yard_id', $id)->orWhereHas('vehicle', fn ($v) => $v->where('yard_id', $id))))
            ->when($request->boolean('mine'), fn ($w) => $w->where('assignee_id', $request->user()->id))
            ->when($qs !== '', fn ($w) => $w->whereHas('vehicle', fn ($v) => $v->where('ref', 'ilike', "%{$qs}%")->orWhere('vin', 'ilike', "%{$qs}%")->orWhere('plate', 'ilike', '%'.mb_strtoupper(str_replace(' ', '', $qs)).'%')
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'ilike', "%{$qs}%")->orWhere('name_ru', 'ilike', "%{$qs}%"))));
        // Связаться — то же условие, что `Request::needsCall()`, но запросом.
        $needsCall = fn ($q) => $q->where('state', RequestState::New)->whereIn('type', [RequestType::Intake, RequestType::Tow])
            ->where(fn ($w) => $w->where(fn ($n) => $n->whereNull('delivery')->whereNull('contacted_at')->whereNull('planned_at'))->orWhere('next_call_at', '<=', now()));
        $q = $filters(Scope::requests($request->user())->with(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.media', 'vehicle.yard', 'yard', 'assignee']));
        $q->whereIn('state', RequestState::open());
        if ($t = RequestType::tryFrom($type)) {
            $q->where('type', $t);
        } elseif ($type === 'call') {
            $needsCall($q);
        } elseif ($type === 'overdue') {
            $q->where('planned_at', '<', now());
        }
        match ($request->query('sort')) {
            'fresh' => $q->latest(),
            'type' => $q->orderBy('type')->orderByRaw('planned_at asc nulls last')->latest(),
            default => $q->orderByRaw('planned_at asc nulls last')->latest(),
        };

        $open = $filters(Scope::requests($request->user()))->whereIn('state', RequestState::open())->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        // Осмотр и перестановка заявками не заводятся: пресеты — только приём, эвакуация, выдача.
        $presets = ['all' => 'Все', 'overdue' => 'Просрочено', 'call' => 'Нужно позвонить'] + collect([RequestType::Intake, RequestType::Tow, RequestType::Release])->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all();

        return view('park.requests.index', [
            'requests' => ListView::paginate($request, $q),
            'preset' => $type,
            'presets' => $presets,
            'counts' => $open->all() + ['all' => $open->sum(), 'call' => $needsCall($filters(Scope::requests($request->user())))->count(),
                'overdue' => $filters(Scope::requests($request->user()))->whereIn('state', RequestState::open())->where('planned_at', '<', now())->count()],
            'sort' => $request->query('sort', 'planned'),
            'q' => $qs,
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** Окошко строки таблицы заявок — окошко ТС: заявка и есть текущий этап дела. */
    public function peek(Request $http, ParkRequest $req, VehicleController $vehicles)
    {
        abort_unless(Scope::allows($http->user(), $req->vehicle), 404);

        return $vehicles->peek($req->vehicle);
    }

    /**
     * Форма заявки. С `?candidate=` поля предзаполнены из письма, письма кандидата — рядом с формой:
     * сотрудник сверяет и сохраняет. Если такую ТС уже завели руками — форма открывается на неё.
     */
    public function create(Request $request, PromoteCandidate $promote)
    {
        // Руками заводится только приём новой ТС; эвакуация с ТС (перегон) — по ссылке «Перегнать».
        $type = RequestType::tryFrom($request->query('type', '')) ?? RequestType::Intake;
        $vehicle = $request->query('car') ? Vehicle::find($request->query('car')) : null;
        if (! in_array($type, [RequestType::Intake, RequestType::Tow], true) || ($type === RequestType::Tow && ! $vehicle)) {
            $type = RequestType::Intake;
        }
        $candidate = $request->query('candidate') ? Candidate::where('scope', MailScope::Park)->where('state', '!=', CandidateState::Promoted)->with(['messages.attachments', 'messages.addresses', 'messages.author'])->find($request->query('candidate')) : null;
        $prefill = [];
        if ($candidate) {
            $v = fn (string $f) => $candidate->value($f);
            $vehicle = $promote->existing($candidate);
            $brand = $v('brand') ? Brand::resolve($v('brand')) : null;
            $model = $brand && $v('model') ? CarModel::resolve($brand, $v('model')) : null;
            $prefill = [
                'ref' => $candidate->code, 'vin' => $v('vin'), 'plate' => $v('plate'),
                'brand' => $brand, 'model' => $model, 'category' => $v('category'),
                'vendor_id' => $v('vendor_id') ?? Vendor::forSender($v('sender'))?->id,
                'contact_name' => $v('insured_name'), 'contact_phone' => $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null,
                'from_address' => $v('location'), 'note' => $candidate->subject,
                'delivery' => $v('request') === 'tow' ? Delivery::Tow->value : null,
                // ВСК пишет, когда и кто привезёт: дата в форму, способ — подсказкой (решает звонок).
                'planned_at' => $v('planned_at') ? str_replace(' ', 'T', $v('planned_at')) : null,
                'delivery_hint' => match ($v('delivery')) {
                    'vendor' => 'привезёт страховая', 'self' => 'привезёт сам', default => ($v('request') === 'tow' ? 'вывоз' : null)
                },
                'flags' => $v('flags') ?: [], 'docs_required' => $v('docs_required') ?: [], 'value' => $v('value'),
            ];
            $type = in_array($type, [RequestType::Intake, RequestType::Tow], true) ? RequestType::Intake : $type;
        }

        return view('park.requests.create', [
            'type' => $type,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'vehicle' => $vehicle,
            'candidate' => $candidate,
            'p' => $prefill,
            'messages' => $candidate?->messages ?? collect(),
        ]);
    }

    public function store(Request $request, CreateRequest $create, PromoteCandidate $promote, LinkThread $link)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(RequestType::class)],
            'vehicle_id' => ['nullable', 'exists:park_vehicles,id'],
            'candidate_id' => ['nullable', 'exists:mail_candidates,id'],
            'delivery' => ['nullable', Rule::enum(Delivery::class)],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'planned_at' => ['nullable', 'date'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'flags' => ['nullable', 'array'], 'flags.*' => ['string', 'max:20'],
            'docs_required' => ['nullable', 'array'], 'docs_required.*' => ['string', 'max:20'],
        ] + VehicleFields::rules());
        $type = RequestType::from($data['type']);
        // Эвакуатор или сам — решается по телефону; «эвакуатор» и есть заявка на эвакуацию.
        // Из письма — только подсказка («В письме: вывоз»): заявка ждёт звонка, а не назначения эвакуатора.
        $delivery = empty($data['candidate_id']) ? Delivery::tryFrom((string) ($data['delivery'] ?? '')) : null;
        if ($type === RequestType::Intake && $delivery === Delivery::Tow) {
            $type = RequestType::Tow;
        } elseif ($type === RequestType::Tow) {
            $delivery = Delivery::Tow;
        }
        $data['delivery'] = $delivery;
        $vehicle = ! empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        if (! in_array($type, [RequestType::Intake, RequestType::Tow], true) && ! $vehicle) {
            return back()->withInput()->withErrors(['vehicle_id' => 'Выберите ТС']);
        }
        $candidate = ! empty($data['candidate_id']) ? Candidate::where('scope', MailScope::Park)->find($data['candidate_id']) : null;
        if ($candidate) {
            $data['thread_id'] = $candidate->thread_id;
            // Кандидата уже завели (двойное нажатие, вторая вкладка) — второй ТС не будет.
            if ($candidate->state === CandidateState::Promoted && $candidate->vehicle_id) {
                return redirect("/cars/{$candidate->vehicle_id}")->with('toast', 'Уже заведена');
            }
            $vehicle ??= $promote->existing($candidate);
        }
        $req = $create($request->user(), $type, $vehicle, $data);
        if ($candidate && $candidate->state !== CandidateState::Promoted) {
            $promote->attach($candidate, $req->vehicle);
        } elseif (! $vehicle) {
            // ТС завели руками — письма с её номером, VIN или госномером уже могли прийти.
            $link->forVehicle($req->vehicle);
        }

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Заявка заведена');
    }

    /** Страницы заявки нет — заявка живёт блоком в деле ТС; старые ссылки (уведомления, закладки) ведут туда. */
    public function show(Request $http, ParkRequest $req)
    {
        return redirect("/cars/{$req->vehicle_id}", 301);
    }

    /** Кто двинул заявку — тот и исполнитель: ставится первым действием, если никого нет. */
    private function claim(Request $request, ParkRequest $req): void
    {
        if (! $req->assignee_id) {
            app(AssignRequest::class)($req, $request->user(), $request->user());
        }
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
        $data = VehicleFields::only($request->validate(VehicleFields::rules()));
        app(UpdateVehicle::class)($vehicle, $data, $request->user());
        $vehicle->refresh();
    }

    public function intake(Request $request, ParkRequest $req, Intake $intake)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16'], 'accepted_at' => ['nullable', 'date']] + self::inspectionRules());
        $this->claim($request, $req);
        $this->saveVehicle($request, $req->vehicle);
        $intake($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, $data, $req, $data['spot'] ?? null);

        // Дальше — дело: шаг «Отчёт вендору» ждёт с черновиком (акт и фото), отправит сотрудник, проверив.
        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Принята');
    }

    public function move(Request $request, ParkRequest $req, Move $move)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        $this->claim($request, $req);
        $this->saveVehicle($request, $req->vehicle);
        $move($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null, $req);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Переставлена');
    }

    /**
     * Выдача с осмотром: «соответствует / не соответствует» — в акт; «не соответствует» и «не забрал» — акт с отказом,
     * ТС остаётся; дальше в обоих случаях редактор письма вендору с актом выдачи.
     */
    public function release(Request $request, ParkRequest $req, Release $release, RefuseRelease $refuse)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000'], 'to' => ['nullable', Rule::enum(ReleasedTo::class)],
            'fits' => ['required', 'boolean'], 'mismatch_note' => ['exclude_if:fits,1', 'required', 'string', 'max:500'], 'refused' => ['boolean']] + self::inspectionRules());
        $at = isset($data['released_at']) ? Carbon::parse($data['released_at']) : null;
        $this->claim($request, $req);
        $this->saveVehicle($request, $req->vehicle);
        $vehicle = $req->vehicle;
        $data['matches'] = $request->boolean('fits');
        if (! $data['matches'] && $request->boolean('refused')) {
            $refuse($vehicle, $request->user(), $at, $data['mismatch_note'], $data, $req);

            return redirect(self::withReport($vehicle->fresh(), 'refusal'))->with('toast', 'Отказ записан, письмо вендору готово');
        }
        $release($vehicle, $request->user(), $at, $data['note'] ?? null, ReleasedTo::tryFrom($data['to'] ?? ''), $data, $req, $request->boolean('force'), $request->boolean('cash'));

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Выдана');
    }

    /** Закрыть заявку сделанной (осмотр, перестановка — старые типы); отмена заявки на приём — «Отменить заявку» на деле (UnwindVehicle). */
    public function close(Request $request, ParkRequest $req, CloseRequest $close)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $close($req, $request->user(), (bool) $data['done'], $data['note'] ?? null);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', $data['done'] ? 'Выполнена' : 'Отменена');
    }

    /** Звонок страхователю: эвакуатор (с полями назначения), привезёт сам (когда), не дозвонились (когда снова). */
    public function contact(Request $request, ParkRequest $req, Contact $contact)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['tow', 'self', 'missed'])],
            'planned_at' => ['nullable', 'date'], 'next_call_at' => ['nullable', 'date'],
            'from_address' => ['nullable', 'string', 'max:255'], 'yard_id' => ['nullable', 'exists:park_yards,id'],
            'carrier' => ['nullable', 'string', 'max:80'], 'distance_km' => ['nullable', 'integer', 'between:0,5000'], 'cost' => ['nullable', 'integer', 'between:0,10000000'],
            'contact_name' => ['nullable', 'string', 'max:80'], 'contact_phone' => ['nullable', 'string', 'max:20'],
        ]);
        $this->claim($request, $req);
        $this->saveVehicle($request, $req->vehicle);
        // Страхователь и телефон — поля ТС; заявка берёт их оттуда, если своих не прислали.
        $data += ['contact_name' => $req->vehicle->contact_name, 'contact_phone' => $req->vehicle->contact_phone];
        $contact($req, $request->user(), $data['outcome'], $data);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', match ($data['outcome']) {
            'tow' => 'Эвакуация', 'self' => 'Привезёт сам', default => 'Позвоним снова'
        });
    }

    public function schedule(Request $request, ParkRequest $req, ScheduleTow $schedule)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate([
            'planned_at' => ['nullable', 'date'], 'from_address' => ['nullable', 'string', 'max:255'], 'yard_id' => ['nullable', 'exists:park_yards,id'],
            'carrier' => ['nullable', 'string', 'max:120'], 'distance_km' => ['nullable', 'integer', 'between:0,5000'], 'cost' => ['nullable', 'integer', 'min:0'],
            'contact_name' => ['nullable', 'string', 'max:80'], 'contact_phone' => ['nullable', 'string', 'max:20'],
        ]);
        if (! empty($data['contact_phone'])) {
            $data['contact_phone'] = Phone::format(Phone::normalize($data['contact_phone']) ?? $data['contact_phone']);
        }
        $this->claim($request, $req);
        $this->saveVehicle($request, $req->vehicle);
        $data += ['contact_name' => $req->vehicle->contact_name, 'contact_phone' => $req->vehicle->contact_phone];
        $schedule($req, $request->user(), $data);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Назначена');
    }

    public function start(Request $request, ParkRequest $req, StartTow $start)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $this->claim($request, $req);
        $start($req, $request->user());

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'В пути');
    }

    public function assign(Request $request, ParkRequest $req, AssignRequest $assign)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['assignee_id' => ['nullable', 'exists:users,id']]);
        $assign($req, ! empty($data['assignee_id']) ? User::find($data['assignee_id']) : null, $request->user());

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Исполнитель записан');
    }

    /** Адрес дела с окном писем поверх, где ждёт черновик вендору с актом и фото; писать некому — просто дело. */
    public static function withReport(Vehicle $vehicle, string $act): string
    {
        $report = $vehicle->reportUrl($act, "/cars/{$vehicle->id}");

        return "/cars/{$vehicle->id}".($report ? '?window='.urlencode($report) : '');
    }

    /** Стоимость эвакуации по прайсу: фикс по категории и площадке плюс километры сверх включённых. */
    public static function towCost(Vehicle $vehicle, ?int $km): ?int
    {
        $fixed = Tariff::resolve($vehicle, TariffService::Tow);
        if (! $fixed) {
            return null;
        }
        $extra = max(0, ($km ?? 0) - ($fixed->km_included ?? 0));
        $perKm = $extra ? Tariff::resolve($vehicle, TariffService::TowKm) : null;

        return (int) round($fixed->price + $extra * ($perKm?->price ?? 0));
    }

    /** @return array<string, list<mixed>> что спрашивает приём и выдача, пока модуля осмотра нет: ключи, документы, кто сдал и подпись */
    public static function inspectionRules(): array
    {
        return [
            'keys_count' => ['nullable', 'integer', 'between:0,9'],
            'docs' => ['nullable', 'array'], 'docs.*' => [Rule::in(array_keys(Inspection::DOCS))],
            'signer_name' => ['nullable', 'string', 'max:120'], 'signature' => ['nullable', 'string', 'max:700000'],
        ];
    }
}
