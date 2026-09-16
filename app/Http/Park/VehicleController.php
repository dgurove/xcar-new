<?php

namespace App\Http\Park;

use App\Cars\DamageZone;
use App\Live\Stream;
use App\Mail\Scope;
use App\Mail\Template;
use App\Mail\Thread;
use App\Media\Actions\RotatePhoto;
use App\Media\PhotoIngest;
use App\Park\Actions\Move;
use App\Park\Actions\Release;
use App\Park\Actions\UpdateVehicle;
use App\Park\Client;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\ListPrefs;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class VehicleController
{
    public const PRESETS = ['stored' => 'На стоянке', 'expected' => 'Ожидаются', 'released' => 'Выданы', 'all' => 'Все'];

    public const SORTS = ['longest' => 'Дольше всех стоят', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'park-vehicles');
        $preset = $request->query('preset', 'stored');
        $q = trim((string) $request->query('q'));
        $vehicles = Vehicle::query()->with(['brand', 'model', 'client', 'yard', 'media'])
            ->when(VehicleState::tryFrom($preset), fn ($v, $s) => $v->where('state', $s))
            ->when($request->query('stoyanka'), fn ($v, $y) => $v->where('yard_id', $y))
            ->when($q !== '', fn ($v) => $v->where(fn ($w) => $w->where('ref_key', 'like', '%'.Vehicle::keyFor($q).'%')->orWhere('vin', 'like', '%'.strtoupper($q).'%')
                ->orWhere('plate', 'like', '%'.mb_strtoupper(preg_replace('/\s+/', '', $q)).'%')->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']))));
        $request->query('sort') === 'fresh' ? $vehicles->latest() : $vehicles->orderByRaw('accepted_at asc nulls last')->latest();

        return view('park.vehicles.index', [
            'vehicles' => $vehicles->paginate(30)->withQueryString(),
            'preset' => $preset,
            'q' => $q,
            'sort' => $request->query('sort', 'longest'),
            'counts' => ['stored' => Vehicle::where('state', VehicleState::Stored)->count(), 'expected' => Vehicle::where('state', VehicleState::Expected)->count()],
            'yard' => $request->query('stoyanka') ? Yard::find($request->query('stoyanka')) : null,
        ]);
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load(['brand', 'model', 'client', 'yard', 'media', 'requests.yard', 'events.user']);

        return view('park.vehicles.show', [
            'vehicle' => $vehicle,
            'yards' => Yard::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'clients' => Client::orderBy('name')->pluck('name', 'id'),
            'threads' => Thread::where('vehicle_id', $vehicle->id)->get(),
            'templates' => Template::where('scope', Scope::Park)->orderBy('name')->get(),
            'zones' => DamageZone::cases(),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, UpdateVehicle $update)
    {
        $data = $request->validate([
            'ref' => ['nullable', 'string', 'max:60'], 'vin' => ['nullable', 'string', 'max:17'], 'plate' => ['nullable', 'string', 'max:12'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)], 'color' => ['nullable', 'string', 'max:32'],
            'brand_id' => ['nullable', 'exists:brands,id'], 'model_id' => ['nullable', 'exists:car_models,id'], 'client_id' => ['nullable', 'exists:park_clients,id'],
            'damage_zones' => ['nullable', 'array'], 'damage_zones.*' => [Rule::enum(DamageZone::class)], 'damage_note' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['damage_zones'] = $data['damage_zones'] ?? [];
        $update($vehicle, $data, $request->user());

        return redirect("/cars/{$vehicle->id}")->with('toast', 'Сохранено');
    }

    public function upload(Request $request, Vehicle $vehicle, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');
        try {
            if ($request->input('collection') === 'papers') {
                $vehicle->addMedia($file)->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $file->getClientOriginalName()) ?: 'dokument')->toMediaCollection('papers');
            } else {
                $ingest->fromPhone($vehicle, 'photos', $request);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($vehicle);
    }

    public function reorder(Request $request, Vehicle $vehicle)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($order, $vehicle->photos()->pluck('id')->all())));

        return $this->gallery($vehicle);
    }

    public function rotateMedia(Vehicle $vehicle, Media $media, RotatePhoto $rotate)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
        $rotate($media);

        return $this->gallery($vehicle);
    }

    public function destroyMedia(Vehicle $vehicle, Media $media)
    {
        abort_unless($media->model_id === $vehicle->id && $media->model_type === $vehicle::class, 404);
        $media->delete();

        return $this->gallery($vehicle);
    }

    public function note(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        $vehicle->log(EventType::Note, $request->user(), ['text' => $data['text']]);

        return back()->with('toast', 'Записано');
    }

    public function move(Request $request, Vehicle $vehicle, Move $move)
    {
        $move($vehicle, $request->user(), Yard::findOrFail($request->validate(['yard_id' => ['required', 'exists:park_yards,id']])['yard_id']));

        return back()->with('toast', 'Переставлена');
    }

    public function release(Request $request, Vehicle $vehicle, Release $release)
    {
        $data = $request->validate(['released_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000']]);
        $release($vehicle, $request->user(), isset($data['released_at']) ? Carbon::parse($data['released_at']) : null, $data['note'] ?? null);

        return back()->with('toast', 'Выдана');
    }

    /** Подсказка для комбобокса: по номеру, VIN, госномеру, марке. */
    public function suggest(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $vehicles = Vehicle::with(['brand', 'model'])->where('state', '!=', VehicleState::Released)
            ->when($q !== '', fn ($v) => $v->where(fn ($w) => $w->where('ref_key', 'like', '%'.Vehicle::keyFor($q).'%')->orWhere('vin', 'like', '%'.strtoupper($q).'%')->orWhere('plate', 'like', '%'.mb_strtoupper($q).'%')))
            ->latest()->limit(20)->get();

        return response()->json($vehicles->map(fn ($v) => ['id' => $v->id, 'label' => $v->titleWithYear(), 'hint' => implode(', ', array_filter([$v->ref, $v->plate, $v->vin]))]));
    }

    private function gallery(Vehicle $vehicle)
    {
        $vehicle->unsetRelation('media');

        return Stream::view('park.vehicles.gallery-stream', ['vehicle' => $vehicle]);
    }
}
