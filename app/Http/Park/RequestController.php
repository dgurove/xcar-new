<?php

namespace App\Http\Park;

use App\Cars\DamageZone;
use App\Park\Actions\CloseRequest;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\Intake;
use App\Park\Actions\Move;
use App\Park\Actions\Release;
use App\Park\Client;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\Yard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RequestController
{
    public const SORTS = ['planned' => 'По сроку', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        $type = $request->query('preset', 'all');
        $done = $request->boolean('gotovye');
        $q = ParkRequest::query()->with(['vehicle.brand', 'vehicle.model', 'vehicle.client', 'vehicle.media', 'yard'])
            ->where('state', $done ? '!=' : '=', RequestState::New);
        if ($t = RequestType::tryFrom($type)) {
            $q->where('type', $t);
        }
        $request->query('sort') === 'fresh' ? $q->latest() : $q->orderByRaw('planned_at asc nulls last')->latest();

        $open = ParkRequest::where('state', RequestState::New)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $presets = ['all' => 'Все'] + RequestType::options();

        return view('park.requests.index', [
            'requests' => $q->paginate(30)->withQueryString(),
            'preset' => $type,
            'presets' => $presets,
            'counts' => $open->all() + ['all' => $open->sum()],
            'done' => $done,
            'sort' => $request->query('sort', 'planned'),
        ]);
    }

    public function create(Request $request)
    {
        return view('park.requests.create', [
            'type' => RequestType::tryFrom($request->query('tip', '')) ?? RequestType::Intake,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'clients' => Client::orderBy('name')->pluck('name', 'id'),
            'vehicle' => $request->query('mashina') ? Vehicle::find($request->query('mashina')) : null,
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
            'client_id' => ['nullable', 'exists:park_clients,id'],
            'yard_id' => ['nullable', 'exists:park_yards,id'],
            'planned_at' => ['nullable', 'date'],
            'contact' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $type = RequestType::from($data['type']);
        $vehicle = ! empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
        if ($type !== RequestType::Intake && ! $vehicle) {
            return back()->withInput()->withErrors(['vehicle_id' => 'Выберите машину']);
        }
        $req = $create($request->user(), $type, $vehicle, $data);

        return redirect("/requests/{$req->id}")->with('toast', 'Заявка заведена');
    }

    public function show(ParkRequest $zayavka)
    {
        $request = $zayavka;
        $request->load(['vehicle.brand', 'vehicle.model', 'vehicle.client', 'vehicle.yard', 'vehicle.media', 'yard', 'thread', 'assignee']);

        return view('park.requests.show', [
            'req' => $request,
            'vehicle' => $request->vehicle,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'zones' => DamageZone::cases(),
        ]);
    }

    public function intake(Request $request, ParkRequest $zayavka, Intake $intake)
    {
        $req = $zayavka;
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id'], 'accepted_at' => ['nullable', 'date'], 'damage_zones' => ['nullable', 'array'], 'damage_note' => ['nullable', 'string', 'max:2000']]);
        $intake($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']), isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : null, $data['damage_zones'] ?? [], $data['damage_note'] ?? null);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Принята');
    }

    public function move(Request $request, ParkRequest $zayavka, Move $move)
    {
        $req = $zayavka;
        $data = $request->validate(['yard_id' => ['required', 'exists:park_yards,id']]);
        $move($req->vehicle, $request->user(), Yard::findOrFail($data['yard_id']));

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Переставлена');
    }

    public function release(Request $request, ParkRequest $zayavka, Release $release)
    {
        $req = $zayavka;
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000']]);
        $release($req->vehicle, $request->user(), isset($data['released_at']) ? Carbon::parse($data['released_at']) : null, $data['note'] ?? null);

        return redirect("/cars/{$req->vehicle_id}")->with('toast', 'Выдана');
    }

    public function close(Request $request, ParkRequest $zayavka, CloseRequest $close)
    {
        $req = $zayavka;
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $close($req, $request->user(), (bool) $data['done'], $data['note'] ?? null);

        return redirect('/')->with('toast', $data['done'] ? 'Выполнена' : 'Отменена');
    }
}
