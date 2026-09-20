<?php

namespace App\Http\Park;

use App\Billing\Ledger;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageZone;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Message;
use App\Mail\Scope as MailScope;
use App\Mail\Thread;
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
use App\Park\Delivery;
use App\Park\Inspection;
use App\Park\PhotoSlot;
use App\Park\ReleasedTo;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Scope;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Phone;
use App\Users\Section;
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
        $done = $request->boolean('done');
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
        $done ? $q->whereNotIn('state', RequestState::open()) : $q->whereIn('state', RequestState::open());
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

        $open = $filters(Scope::requests($request->user()))->when($done, fn ($q) => $q->whereNotIn('state', RequestState::open()), fn ($q) => $q->whereIn('state', RequestState::open()))->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $presets = ['all' => 'Все', 'overdue' => 'Просрочено', 'call' => 'Связаться'] + RequestType::options();

        return view('park.requests.index', [
            'requests' => ListView::paginate($request, $q),
            'preset' => $type,
            'presets' => $presets,
            'counts' => $open->all() + ['all' => $open->sum(), 'call' => $done ? 0 : $needsCall($filters(Scope::requests($request->user())))->count(),
                'overdue' => $done ? 0 : $filters(Scope::requests($request->user()))->whereIn('state', RequestState::open())->where('planned_at', '<', now())->count()],
            'done' => $done,
            'sort' => $request->query('sort', 'planned'),
            'q' => $qs,
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** Окошко строки таблицы: состояние, срок, контакт, исполнитель; действия — ссылками на страницу заявки. */
    public function peek(Request $http, ParkRequest $req)
    {
        $req->load(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.yard', 'vehicle.media', 'yard', 'assignee', 'doneBy']);
        abort_unless(Scope::allows($http->user(), $req->vehicle), 404);

        return view('park.requests.peek', ['req' => $req, 'vehicle' => $req->vehicle]);
    }

    /**
     * Форма заявки. С `?candidate=` поля предзаполнены из письма, письма кандидата — рядом с формой:
     * сотрудник сверяет и сохраняет. Если такую ТС уже завели руками — форма открывается на неё.
     */
    public function create(Request $request, PromoteCandidate $promote)
    {
        $type = RequestType::tryFrom($request->query('type', '')) ?? RequestType::Intake;
        $vehicle = $request->query('car') ? Vehicle::find($request->query('car')) : null;
        $candidate = $request->query('candidate') ? Candidate::where('scope', MailScope::Park)->where('state', '!=', CandidateState::Promoted)->with(['messages.attachments', 'messages.addresses', 'messages.author'])->find($request->query('candidate')) : null;
        $prefill = [];
        if ($candidate) {
            $v = fn (string $f) => $candidate->value($f);
            $vehicle = $promote->existing($candidate);
            $brand = $v('brand') ? Brand::resolve($v('brand')) : null;
            $model = $brand && $v('model') ? CarModel::resolve($brand, $v('model')) : null;
            $prefill = [
                'ref' => $candidate->code, 'vin' => $v('vin'), 'plate' => $v('plate'), 'color' => $v('color'),
                'brand' => $brand, 'model' => $model, 'category' => $v('category'),
                'vendor_id' => $v('vendor_id') ?? Vendor::forSender($v('sender'))?->id,
                'contact_name' => $v('insured_name'), 'contact_phone' => $v('insured_phone') ?? ((array) $v('phones'))[0] ?? null,
                'from_address' => $v('location'), 'note' => $candidate->subject,
                'delivery' => $v('request') === 'tow' ? Delivery::Tow->value : null,
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

    public function store(Request $request, CreateRequest $create, PromoteCandidate $promote)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(RequestType::class)],
            'vehicle_id' => ['nullable', 'exists:park_vehicles,id'],
            'candidate_id' => ['nullable', 'exists:mail_candidates,id'],
            'delivery' => ['nullable', Rule::enum(Delivery::class)],
            'ref' => ['nullable', 'string', 'max:60'],
            'vin' => ['nullable', 'string', 'max:17'],
            'plate' => ['nullable', 'string', 'max:12'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'color' => ['nullable', 'string', 'max:32'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model_id' => ['nullable', 'exists:car_models,id'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'category' => ['nullable', Rule::enum(Category::class)],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'planned_at' => ['nullable', 'date'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'flags' => ['nullable', 'array'], 'flags.*' => ['string', 'max:20'],
            'docs_required' => ['nullable', 'array'], 'docs_required.*' => ['string', 'max:20'],
            'value' => ['nullable', 'integer', 'min:0'],
        ]);
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
        }

        return redirect("/requests/{$req->id}")->with('toast', 'Заявка заведена');
    }

    public function show(Request $http, ParkRequest $req)
    {
        $req->load(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.yard', 'vehicle.media', 'vehicle.requests', 'vehicle.events.user', 'yard', 'thread', 'assignee']);
        abort_unless(Scope::allows($http->user(), $req->vehicle), 404);
        $vehicle = $req->vehicle;
        $yards = Yard::where('is_active', true)->orderBy('name')->get();
        // Письма всех веток ТС — рядом с формой, чтобы заполнять, глядя в письмо.
        $messages = Message::whereIn('thread_id', Thread::where('vehicle_id', $vehicle->id)->select('id'))->with(['attachments', 'addresses', 'author'])->orderByDesc('date_at')->get();

        return view('park.requests.show', [
            'req' => $req,
            'vehicle' => $vehicle,
            'messages' => $messages,
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'zones' => DamageZone::cases(),
            'slots' => PhotoSlot::cases(),
            'shots' => $vehicle->photos()->map(fn ($m) => $m->getCustomProperty('slot'))->filter()->countBy()->all(),
            'staff' => User::where(fn ($q) => $q->whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin'))->whereNotNull('approved_at')->whereNull('rejected_at')->orderBy('name')->get(),
            'towCost' => $req->isTow() ? self::towCost($vehicle, $req->distance_km) : null,
            // Выдача при долге держится, если у вендора не разрешено выдавать без оплаты: показать долг и «выдать с долгом».
            'debt' => $req->type === RequestType::Release ? Ledger::vehicleDebt($vehicle) + Ledger::vehicleUnbilled($vehicle) : 0,
            'buyerDebt' => $req->type === RequestType::Release ? Ledger::buyerDebt($vehicle) : 0,
            'debtBlocks' => $req->type === RequestType::Release && ! ($vehicle->vendor?->release_without_payment ?? false),
            // Перевозчики — кого уже возили: подсказка в поле, отдельного справочника нет.
            'carriers' => $req->isTow() ? ParkRequest::whereNotNull('carrier')->where('carrier', '!=', '')->selectRaw('carrier, count(*) as n')->groupBy('carrier')->orderByDesc('n')->limit(20)->pluck('carrier') : collect(),
            'storageRate' => Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'events' => $vehicle->events->sortByDesc('created_at')->take(12),
        ]);
    }

    public function intake(Request $request, ParkRequest $req, Intake $intake)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16'], 'accepted_at' => ['nullable', 'date'], 'category' => ['nullable', Rule::enum(Category::class)], 'oversize' => ['boolean']] + self::inspectionRules());
        if (! empty($data['category'])) {
            $req->vehicle->update(['category' => $data['category'], 'oversize' => $request->boolean('oversize')]);
        }
        $intake($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, $data, $req, $data['spot'] ?? null);
        // Дальше — редактор письма вендору с актом и фото приёма; отправит сотрудник, проверив.
        $report = $req->vehicle->fresh()->reportUrl('intake', "/cars/{$req->vehicle_id}");

        return redirect($report ?? "/cars/{$req->vehicle_id}")->with('toast', $report ? 'Принята, письмо вендору готово' : 'Принята');
    }

    public function move(Request $request, ParkRequest $req, Move $move)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
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
        $vehicle = $req->vehicle;
        $data['matches'] = $request->boolean('fits');
        if (! $data['matches'] && $request->boolean('refused')) {
            $refuse($vehicle, $request->user(), $at, $data['mismatch_note'], $data, $req);
            $report = $vehicle->fresh()->reportUrl('refusal', "/requests/{$req->id}");

            return redirect($report ?? "/requests/{$req->id}")->with('toast', $report ? 'Отказ записан, письмо вендору готово' : 'Отказ записан');
        }
        $release($vehicle, $request->user(), $at, $data['note'] ?? null, ReleasedTo::tryFrom($data['to'] ?? ''), $data, $req, $request->boolean('force'), $request->boolean('cash'));
        $report = $vehicle->fresh()->reportUrl('release', "/cars/{$req->vehicle_id}");

        return redirect($report ?? "/cars/{$req->vehicle_id}")->with('toast', $report ? 'Выдана, письмо вендору готово' : 'Выдана');
    }

    public function close(Request $request, ParkRequest $req, CloseRequest $close)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $close($req, $request->user(), (bool) $data['done'], $data['note'] ?? null);

        // Сделана — к ТС; отменена — в заявки (ТС в ожидании без заявки видна в «Ожидаются»).
        return redirect($data['done'] ? "/cars/{$req->vehicle_id}" : '/requests')->with('toast', $data['done'] ? 'Выполнена' : 'Отменена');
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
        $contact($req, $request->user(), $data['outcome'], $data);

        return redirect("/requests/{$req->id}")->with('toast', match ($data['outcome']) {
            'tow' => 'Эвакуация', 'self' => 'Привезёт сам', default => 'Позвоним снова'
        });
    }

    /** «Беру» — исполнитель я, без шторки. */
    public function take(Request $request, ParkRequest $req, AssignRequest $assign)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $assign($req, $request->user(), $request->user());

        return back()->with('toast', 'Ваша');
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
        $schedule($req, $request->user(), $data);

        return redirect("/requests/{$req->id}")->with('toast', 'Назначена');
    }

    public function start(Request $request, ParkRequest $req, StartTow $start)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $start($req, $request->user());

        return redirect("/requests/{$req->id}")->with('toast', 'В пути');
    }

    public function assign(Request $request, ParkRequest $req, AssignRequest $assign)
    {
        abort_unless(Scope::allows($request->user(), $req->vehicle), 404);
        $data = $request->validate(['assignee_id' => ['nullable', 'exists:users,id']]);
        $assign($req, ! empty($data['assignee_id']) ? User::find($data['assignee_id']) : null, $request->user());

        return redirect("/requests/{$req->id}")->with('toast', 'Исполнитель записан');
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

    /** @return array<string, list<mixed>> правила осмотра — общие для приёма и выдачи */
    public static function inspectionRules(): array
    {
        return [
            'mileage' => ['nullable', 'integer', 'between:0,9999999'], 'fuel' => ['nullable', 'integer', 'between:0,8'], 'keys_count' => ['nullable', 'integer', 'between:0,9'],
            'docs' => ['nullable', 'array'], 'docs.*' => [Rule::in(array_keys(Inspection::DOCS))],
            'equipment' => ['nullable', 'array'], 'equipment.*' => [Rule::in(array_keys(Inspection::EQUIPMENT))],
            'repair' => ['nullable', 'array'], 'repair.*' => ['nullable', 'in:0,1'],
            'damage_zones' => ['nullable', 'array'], 'damage_zones.*' => [Rule::enum(DamageZone::class)],
            'damage_note' => ['nullable', 'string', 'max:2000'], 'transit_damage' => ['nullable', 'string', 'max:2000'],
            'missing_parts' => ['nullable', 'string', 'max:1000'], 'replaced_units' => ['nullable', 'string', 'max:1000'], 'signer_name' => ['nullable', 'string', 'max:120'], 'signature' => ['nullable', 'string', 'max:700000'],
        ];
    }
}
