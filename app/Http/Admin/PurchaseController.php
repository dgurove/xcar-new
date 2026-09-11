<?php

namespace App\Http\Admin;

use App\Purchases\Actions\ChangePurchaseState;
use App\Purchases\Actions\ChooseOffer;
use App\Purchases\Actions\CreatePurchase;
use App\Purchases\Actions\ImportFile;
use App\Purchases\Actions\UpdateCar;
use App\Purchases\Car;
use App\Purchases\Export;
use App\Purchases\ImportState;
use App\Purchases\Importer;
use App\Purchases\Jobs\FetchPhotos;
use App\Purchases\Jobs\FetchSpecs;
use App\Purchases\Kind;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Purchases\Restriction;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController
{
    public const PRESETS = ['all' => 'Все', 'offers' => 'С ценами', 'attention' => 'Требуют внимания', 'nophoto' => 'Без фото'];

    public function index()
    {
        return view('admin.purchases.index', [
            'purchases' => Purchase::withCount('cars')->orderByDesc('number')->get(),
            'restricted' => Restriction::count(),
        ]);
    }

    public function store(Request $request, CreatePurchase $create)
    {
        $purchase = $create($request->validate(['title' => ['nullable', 'string', 'max:120'], 'supplier' => ['nullable', 'string', 'max:80'], 'offers_close_at' => ['nullable', 'date']]));

        return redirect("/admin/zakupki/{$purchase->number}");
    }

    public function show(Request $request, Purchase $purchase)
    {
        $preset = $request->query('preset', 'all');
        $q = trim((string) $request->query('q'));
        $cars = $purchase->cars()->with(['brand', 'model', 'media', 'offers.user'])
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(dl) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(brand_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('vin', 'like', '%'.strtoupper($q).'%')));
        match ($preset) {
            'offers' => $cars->whereHas('offers', fn ($o) => $o->whereIn('state', [OfferState::Active, OfferState::Chosen])),
            'attention' => $cars->where(fn ($w) => $w->whereIn('specs_state', ['failed', 'partial', 'gone'])->orWhereIn('photos_state', ['failed', 'partial', 'gone'])),
            'nophoto' => $cars->where('photos_count', 0),
            default => null,
        };
        $all = $purchase->cars()->with('offers')->get();
        $best = $all->map(fn ($c) => $c->bestOffer()?->amount ?? 0);

        return view('admin.purchases.show', [
            'purchase' => $purchase,
            'cars' => $cars->orderBy('dl')->paginate(50)->withQueryString(),
            'preset' => $preset,
            'q' => $q,
            'stats' => [
                'cars' => $all->count(),
                'photos' => $all->where('photos_count', '>', 0)->count(),
                'priced' => $all->filter(fn ($c) => $c->bestOffer())->count(),
                'sum' => $best->sum(),
                'ours' => $all->filter(fn ($c) => $c->bestOffer())->sum('price_listing'),
                'pending' => $all->filter(fn ($c) => in_array($c->specs_state, [ImportState::Pending, ImportState::Running], true) || in_array($c->photos_state, [ImportState::Pending, ImportState::Running], true))->count(),
            ],
            'transitions' => array_filter(PurchaseState::cases(), fn ($s) => $s !== $purchase->state),
        ]);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $purchase->update($request->validate(['title' => ['nullable', 'string', 'max:120'], 'supplier' => ['nullable', 'string', 'max:80'], 'offers_close_at' => ['nullable', 'date']]));

        return back()->with('toast', 'Сохранено');
    }

    public function state(Request $request, Purchase $purchase, ChangePurchaseState $change)
    {
        $change($purchase, PurchaseState::from($request->validate(['state' => ['required', Rule::enum(PurchaseState::class)]])['state']), $request->user());

        return back()->with('toast', $purchase->fresh()->state->label());
    }

    public function upload(Request $request, Purchase $purchase, Importer $importer)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']]);
        $path = $request->file('file')->storeAs("purchases/{$purchase->id}", now()->format('Ymd-His').'.xlsx', 'private');
        try {
            $preview = $importer->preview($purchase, Storage::disk('private')->path($path));
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return view('admin.purchases.import', ['purchase' => $purchase, 'path' => $path, 'rows' => $preview['rows'], 'stats' => $preview['stats']]);
    }

    public function import(Request $request, Purchase $purchase, ImportFile $import)
    {
        $path = $request->validate(['path' => ['required', 'string']])['path'];
        abort_unless(str_starts_with($path, "purchases/{$purchase->id}/") && Storage::disk('private')->exists($path), 404);
        $result = $import($purchase, Storage::disk('private')->path($path), $request->user());
        $purchase->update(['source_file' => $path]);

        return redirect("/admin/zakupki/{$purchase->number}")->with('toast', "Новых {$result['created']}, обновлено {$result['updated']}".($result['skipped'] ? ", пропущено {$result['skipped']}" : ''));
    }

    public function export(Purchase $purchase, Export $export)
    {
        $path = storage_path("app/private/purchases/{$purchase->id}/itog-".now()->format('Ymd-His').'.xlsx');
        @mkdir(dirname($path), 0775, true);
        $export->write($purchase, $path);

        return response()->download($path, "zakupka-{$purchase->number}.xlsx")->deleteFileAfterSend();
    }

    public function refetch(Request $request, Purchase $purchase, ImportFile $import)
    {
        $n = $import->refetch($purchase, $request->boolean('specs'), $request->boolean('photos'));

        return back()->with('toast', "В очереди: {$n}");
    }

    public function restrictions(Request $request)
    {
        return view('admin.purchases.restrictions', [
            'users' => User::where('role', Role::Manager)->orderBy('name')->get(),
            'restrictions' => Restriction::all()->keyBy('user_id'),
        ]);
    }

    public function restrict(Request $request, User $user)
    {
        $kinds = array_values(array_intersect((array) $request->input('hidden', []), array_column(Kind::cases(), 'value')));
        $kinds ? Restriction::updateOrCreate(['user_id' => $user->id], ['hidden_kinds' => $kinds]) : Restriction::where('user_id', $user->id)->delete();

        return back()->with('toast', 'Сохранено');
    }

    // ---------------------------------------------------------------- машина

    public function car(Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $car->load(['brand', 'model', 'settlement', 'media', 'offers.user']);

        return view('admin.purchases.car', ['purchase' => $purchase, 'car' => $car, 'settlements' => \App\Cars\Settlement::orderByDesc('is_federal_city')->orderBy('name')->pluck('name', 'id')]);
    }

    public function updateCar(Request $request, Purchase $purchase, Car $car, UpdateCar $update)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $data = $request->validate([
            'brand_id' => ['nullable', 'exists:brands,id'], 'model_id' => ['nullable', 'exists:car_models,id'], 'year' => ['nullable', 'integer'], 'vin' => ['nullable', 'string', 'max:17'],
            'mileage' => ['nullable', 'string'], 'transmission' => ['nullable', 'string'], 'fuel' => ['nullable', 'string'], 'engine_volume' => ['nullable', 'string'], 'engine_power' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:32'], 'settlement_id' => ['nullable', 'exists:settlements,id'], 'address' => ['nullable', 'string', 'max:255'], 'kind' => ['required', Rule::enum(Kind::class)],
            'price_revalued' => ['nullable', 'string'], 'price_listing' => ['nullable', 'string'], 'description' => ['nullable', 'string', 'max:5000'], 'condition' => ['nullable', 'string', 'max:120'],
        ]);
        foreach (['mileage', 'engine_volume', 'engine_power', 'price_revalued', 'price_listing'] as $n) {
            $data[$n] = isset($data[$n]) && $data[$n] !== '' ? (int) preg_replace('/\D+/', '', $data[$n]) : null;
        }
        $data['transmission'] = $data['transmission'] ?: null;
        $data['fuel'] = $data['fuel'] ?: null;
        $data['is_published'] = $request->boolean('is_published');
        $update($car, $data);

        return back()->with('toast', 'Сохранено');
    }

    public function fetch(Request $request, Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        if ($request->input('what') === 'specs') {
            $car->update(['specs_state' => ImportState::Pending]);
            FetchSpecs::dispatch($car->id);
        } else {
            $car->update(['photos_state' => ImportState::Pending]);
            FetchPhotos::dispatch($car->id, all: $request->boolean('all'));
        }

        return back()->with('toast', 'В очереди');
    }

    public function choose(Request $request, Offer $offer, ChooseOffer $choose)
    {
        $choose($offer, $request->user());

        return back()->with('toast', 'Выбрана');
    }
}
