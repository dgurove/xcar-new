<?php

namespace App\Http\Park;

use App\Cars\Category;
use App\Cars\DamageZone;
use App\Park\Actions\AssignRequest;
use App\Park\Actions\CloseRequest;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\Intake;
use App\Park\Actions\Move;
use App\Park\Actions\Release;
use App\Park\Actions\ScheduleTow;
use App\Park\Actions\StartTow;
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

    /** Список заявок: пресеты типов и «Готовые», поиск по ТС, вендор, площадка, «Мои»; три вида с окошком строки. */
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
        $q = $filters(Scope::requests($request->user())->with(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.media', 'vehicle.yard', 'yard', 'assignee']));
        $done ? $q->whereNotIn('state', RequestState::open()) : $q->whereIn('state', RequestState::open());
        if ($t = RequestType::tryFrom($type)) {
            $q->where('type', $t);
        }
        match ($request->query('sort')) {
            'fresh' => $q->latest(),
            'type' => $q->orderBy('type')->orderByRaw('planned_at asc nulls last')->latest(),
            default => $q->orderByRaw('planned_at asc nulls last')->latest(),
        };

        $open = $filters(Scope::requests($request->user()))->when($done, fn ($q) => $q->whereNotIn('state', RequestState::open()), fn ($q) => $q->whereIn('state', RequestState::open()))->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $presets = ['all' => 'Все'] + RequestType::options();

        return view('park.requests.index', [
            'requests' => ListView::paginate($request, $q),
            'preset' => $type,
            'presets' => $presets,
            'counts' => $open->all() + ['all' => $open->sum()],
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

    public function create(Request $request)
    {
        return view('park.requests.create', [
            'type' => RequestType::tryFrom($request->query('type', '')) ?? RequestType::Intake,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'categories' => Category::options(),
            'vehicle' => $request->query('car') ? Vehicle::find($request->query('car')) : null,
        ]);
    }

    public function store(Request $request, CreateRequest $create)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(RequestType::class)],
            'vehicle_id' => ['nullable', 'exists:park_vehicles,id'],
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
        ]);
        $type = RequestType::from($data['type']);
        $vehicle = ! empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        if (! in_array($type, [RequestType::Intake, RequestType::Tow], true) && ! $vehicle) {
            return back()->withInput()->withErrors(['vehicle_id' => 'Выберите ТС']);
        }
        $req = $create($request->user(), $type, $vehicle, $data);

        return redirect("/requests/{$req->id}")->with('toast', 'Заявка заведена');
    }

    public function show(Request $http, ParkRequest $req)
    {
        $req->load(['vehicle.brand', 'vehicle.model', 'vehicle.vendor', 'vehicle.yard', 'vehicle.media', 'vehicle.requests', 'yard', 'thread', 'assignee']);
        abort_unless(Scope::allows($http->user(), $req->vehicle), 404);
        $vehicle = $req->vehicle;
        $yards = Yard::where('is_active', true)->orderBy('name')->get();

        return view('park.requests.show', [
            'req' => $req,
            'vehicle' => $vehicle,
            'yards' => $yards->pluck('name', 'id'),
            'yardRows' => $yards->mapWithKeys(fn ($y) => [$y->id => $y->freeSpots()]),
            'zones' => DamageZone::cases(),
            'slots' => PhotoSlot::cases(),
            'shots' => $vehicle->photos()->map(fn ($m) => $m->getCustomProperty('slot'))->filter()->countBy()->all(),
            'staff' => User::whereJsonContains('access', Section::Park->value)->orWhere('role', 'admin')->orderBy('name')->get(),
            'towCost' => $req->isTow() ? self::towCost($vehicle, $req->distance_km) : null,
            // Перевозчики — кого уже возили: подсказка в поле, отдельного справочника нет.
            'carriers' => $req->isTow() ? ParkRequest::whereNotNull('carrier')->where('carrier', '!=', '')->selectRaw('carrier, count(*) as n')->groupBy('carrier')->orderByDesc('n')->limit(20)->pluck('carrier') : collect(),
            'storageRate' => Tariff::ladderLabel(Tariff::ladderFor($vehicle, TariffService::Storage)),
        ]);
    }

    public function intake(Request $request, ParkRequest $req, Intake $intake)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16'], 'accepted_at' => ['nullable', 'date'], 'category' => ['nullable', Rule::enum(Category::class)], 'oversize' => ['boolean']] + self::inspectionRules());
        if (! empty($data['category'])) {
            $req->vehicle->update(['category' => $data['category'], 'oversize' => $request->boolean('oversize')]);
        }
        $intake($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, $data, $req, $data['spot'] ?? null);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Принята');
    }

    public function move(Request $request, ParkRequest $req, Move $move)
    {
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'spot' => ['nullable', 'string', 'max:16']]);
        $move($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), $data['spot'] ?? null, $req);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Переставлена');
    }

    public function release(Request $request, ParkRequest $req, Release $release)
    {
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000'], 'to' => ['nullable', Rule::enum(ReleasedTo::class)]] + self::inspectionRules());
        $release($req->vehicle, $request->user(), isset($data['released_at']) ? Carbon::parse($data['released_at']) : null, $data['note'] ?? null, ReleasedTo::tryFrom($data['to'] ?? ''), $data, $req, $request->boolean('force'));

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Выдана');
    }

    public function close(Request $request, ParkRequest $req, CloseRequest $close)
    {
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $close($req, $request->user(), (bool) $data['done'], $data['note'] ?? null);

        return redirect('/')->with('toast', $data['done'] ? 'Выполнена' : 'Отменена');
    }

    public function schedule(Request $request, ParkRequest $req, ScheduleTow $schedule)
    {
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
        $start($req, $request->user());

        return redirect("/requests/{$req->id}")->with('toast', 'В пути');
    }

    public function assign(Request $request, ParkRequest $req, AssignRequest $assign)
    {
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
