<?php

namespace App\Http\Admin;

use App\Cars\Settlement;
use App\Purchases\Actions\ChangePurchaseState;
use App\Purchases\Actions\ChooseOffer;
use App\Purchases\Actions\CreatePurchase;
use App\Purchases\Actions\ImportFile;
use App\Purchases\Actions\UnchooseOffer;
use App\Purchases\Actions\UpdateCar;
use App\Purchases\Car;
use App\Purchases\Export;
use App\Purchases\Importer;
use App\Purchases\ImportState;
use App\Purchases\Jobs\FetchPhotos;
use App\Purchases\Jobs\FetchSpecs;
use App\Purchases\Kind;
use App\Purchases\Offer;
use App\Purchases\OffersExport;
use App\Purchases\OffersSummary;
use App\Purchases\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Purchases\Restriction;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController
{
    public const PRESETS = ['all' => 'Все', 'priced' => 'С предложениями', 'unpriced' => 'Без предложений', 'attention' => 'Требуют внимания', 'nophoto' => 'Без фото', 'hidden' => 'Скрытые'];

    public const SORTS = ['dl' => 'По порядку файла', 'best' => 'Лучшая цена', 'fresh' => 'Сначала новые'];

    public const VIEWS = ['cars' => 'По машинам', 'managers' => 'По менеджерам'];

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

        return redirect("/zakupki/{$purchase->number}");
    }

    /** Один экран-просмотр: по машинам (все цены у каждой) или по менеджерам (цены одного человека и где их нет). */
    public function show(Request $request, Purchase $purchase)
    {
        $view = $request->query('view') === 'managers' ? 'managers' : 'cars';
        $q = trim((string) $request->query('q'));
        $pending = $purchase->cars()->where(fn ($w) => $w->whereIn('specs_state', ['pending', 'running'])->orWhereIn('photos_state', ['pending', 'running']))->count();
        $data = ['purchase' => $purchase, 'view' => $view, 'q' => $q, 'pending' => $pending, 'transitions' => array_filter(PurchaseState::cases(), fn ($s) => $s !== $purchase->state)];

        return view('admin.purchases.show', $data + ($view === 'cars' ? $this->byCars($request, $purchase, $q) : $this->byManagers($request, $purchase, $q)));
    }

    private function search(string $q): \Closure
    {
        return fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(dl) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(brand_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(model_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('vin', 'like', '%'.strtoupper($q).'%'));
    }

    private function byCars(Request $request, Purchase $purchase, string $q): array
    {
        $preset = array_key_exists($request->query('preset', 'all'), self::PRESETS) ? $request->query('preset', 'all') : 'all';
        $sort = array_key_exists($request->query('sort', 'dl'), self::SORTS) ? $request->query('sort', 'dl') : 'dl';
        $live = fn ($o) => $o->whereIn('state', [OfferState::Active, OfferState::Chosen]);
        $cars = $purchase->cars()->with(['brand', 'model', 'settlement', 'media', 'offers.user'])->when($q !== '', $this->search($q));
        match ($preset) {
            'priced' => $cars->whereHas('offers', $live),
            'unpriced' => $cars->whereDoesntHave('offers', $live),
            'attention' => $cars->where(fn ($w) => $w->whereIn('specs_state', ['failed', 'partial', 'gone'])->orWhereIn('photos_state', ['failed', 'partial', 'gone'])),
            'nophoto' => $cars->where('photos_count', 0),
            'hidden' => $cars->where('is_published', false),
            default => null,
        };
        match ($sort) {
            'best' => $cars->withMax(['offers as top_offer' => $live], 'amount')->orderByDesc('top_offer')->orderBy('dl'),
            'fresh' => $cars->orderByDesc('id'),
            default => $cars->orderBy('dl'),
        };
        // Числа на пилюлях — одним проходом, без поиска: пилюля говорит о закупке, а не о выдаче.
        $all = $purchase->cars()->with('offers')->get();
        $counts = [
            'all' => $all->count(),
            'priced' => $all->filter(fn ($c) => $c->activeOfferList()->isNotEmpty())->count(),
            'unpriced' => $all->filter(fn ($c) => $c->activeOfferList()->isEmpty())->count(),
            'attention' => $all->filter(fn ($c) => $c->specs_state->needsAttention() || $c->photos_state->needsAttention())->count(),
            'nophoto' => $all->where('photos_count', 0)->count(),
            'hidden' => $all->where('is_published', false)->count(),
        ];

        return ['cars' => $cars->paginate(50)->withQueryString(), 'preset' => $preset, 'sort' => $sort, 'counts' => $counts];
    }

    private function byManagers(Request $request, Purchase $purchase, string $q): array
    {
        $summary = new OffersSummary($purchase);
        $sort = array_key_exists($request->query('sort', 'dl'), self::SORTS) ? $request->query('sort', 'dl') : 'dl';
        $pick = $request->query('user');
        $user = $pick === 'none' ? null : ($summary->managers->firstWhere('id', (int) $pick) ?? $summary->managers->first());
        $has = $request->boolean('has', true);
        if ($user) {
            $hidden = Restriction::hiddenFor($user);
            $cars = $has
                ? $summary->offersOf($user)->map->car
                : $summary->cars->filter(fn ($c) => ! in_array($c->kind->value, $hidden, true) && ! $c->activeOfferList()->firstWhere('user_id', $user->id));
        } else {
            $cars = $summary->unpriced();
        }
        if ($q !== '') {
            $term = mb_strtolower($q);
            $cars = $cars->filter(fn ($c) => str_contains(mb_strtolower($c->dl.' '.$c->brand_raw.' '.$c->model_raw.' '.$c->vin), $term));
        }
        $cars = match ($sort) {
            'best' => $cars->sortByDesc(fn ($c) => $c->bestOffer()?->amount ?? 0),
            'fresh' => $cars->sortByDesc('id'),
            default => $cars->sortBy('dl'),
        };
        $page = max(1, (int) $request->query('page', 1));
        // Связи для показа — только у страницы: сводка считается по всем машинам, а рисуются пятьдесят.
        $slice = (new EloquentCollection($cars->values()->forPage($page, 50)->values()->all()))->load(['brand', 'model', 'settlement', 'media']);
        $cars = new LengthAwarePaginator($slice, $cars->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return ['cars' => $cars, 'summary' => $summary, 'user' => $user, 'has' => $has, 'sort' => $sort];
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
            $importer->preview($purchase, Storage::disk('private')->path($path));
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // POST отвечает редиректом, иначе Turbo молча роняет ответ; предпросмотр — своей страницей.
        return redirect("/zakupki/{$purchase->number}/import?".http_build_query(['path' => $path]));
    }

    public function preview(Request $request, Purchase $purchase, Importer $importer)
    {
        $path = $request->query('path', '');
        abort_unless(str_starts_with($path, "purchases/{$purchase->id}/") && Storage::disk('private')->exists($path), 404);
        $preview = $importer->preview($purchase, Storage::disk('private')->path($path));

        return view('admin.purchases.import', ['purchase' => $purchase, 'path' => $path, 'rows' => $preview['rows'], 'stats' => $preview['stats']]);
    }

    public function import(Request $request, Purchase $purchase, ImportFile $import)
    {
        $path = $request->validate(['path' => ['required', 'string']])['path'];
        abort_unless(str_starts_with($path, "purchases/{$purchase->id}/") && Storage::disk('private')->exists($path), 404);
        $result = $import($purchase, Storage::disk('private')->path($path), $request->user());
        $purchase->update(['source_file' => $path]);

        return redirect("/zakupki/{$purchase->number}")->with('toast', "Новых {$result['created']}, обновлено {$result['updated']}".($result['skipped'] ? ", пропущено {$result['skipped']}" : ''));
    }

    public function export(Purchase $purchase, Export $export)
    {
        $path = storage_path("app/private/purchases/{$purchase->id}/itog-".now()->format('Ymd-His').'.xlsx');
        @mkdir(dirname($path), 0775, true);
        $export->write($purchase, $path);

        return response()->download($path, "zakupka-{$purchase->number}.xlsx")->deleteFileAfterSend();
    }

    public function offersExport(Request $request, Purchase $purchase, OffersExport $export)
    {
        $data = $request->validate([
            'sheets' => 'required|array|min:1',
            'sheets.*' => Rule::in(array_keys(OffersExport::SHEETS)),
            'format' => 'required|in:xlsx,pdf',
        ]);
        $path = storage_path("app/private/purchases/{$purchase->id}/predlozheniya-".now()->format('Ymd-His').'.'.$data['format']);
        @mkdir(dirname($path), 0775, true);
        $tables = $export->tables($purchase, $data['sheets']);
        $data['format'] === 'pdf' ? $export->pdf($tables, $purchase, $path) : $export->xlsx($tables, $path);

        return response()->download($path, "zakupka-{$purchase->number}-predlozheniya.{$data['format']}")->deleteFileAfterSend();
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

        return view('admin.purchases.car', ['purchase' => $purchase, 'car' => $car, 'settlements' => Settlement::orderByDesc('is_federal_city')->orderBy('name')->pluck('name', 'id')]);
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

    public function unchoose(Request $request, Offer $offer, UnchooseOffer $unchoose)
    {
        abort_unless($offer->state === OfferState::Chosen, 404);
        $unchoose($offer, $request->user());

        return back()->with('toast', 'Выбор отменён');
    }
}
