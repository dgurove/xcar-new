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
use App\Purchases\Kind;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Purchases\Restriction;
use App\Support\ListPrefs;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController
{
    public const PRESETS = ['all' => 'Все ТС', 'priced' => 'С предложениями', 'unpriced' => 'Без предложений', 'unfinal' => 'Без нашей цены', 'final' => 'С нашей ценой', 'attention' => 'Требуют внимания', 'nophoto' => 'Без фото', 'hidden' => 'Скрытые'];

    /** Пресеты группами в выборе «Все ТС ▾»: из каждой группы выбирают один ответ. */
    public const PRESET_GROUPS = ['' => ['all'], 'Предложения менеджеров' => ['priced', 'unpriced'], 'Наша цена' => ['unfinal', 'final'], 'Служебное' => ['attention', 'nophoto', 'hidden']];

    public const SORTS = ['dl' => 'По порядку файла', 'best' => 'Лучшая цена', 'final' => 'Наша цена', 'fresh' => 'Сначала новые'];

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

        return redirect("/purchases/{$purchase->number}");
    }

    /**
     * Один список с фильтрами: тип (пилюли тулбара), состояние и менеджер (выборы), поиск,
     * сортировка — всё в адресе и сочетается. Менеджер выбран — только машины с его живой
     * ценой, его чип первый.
     */
    public function show(Request $request, Purchase $purchase)
    {
        ListPrefs::sync($request, 'crm-purchase-cars');
        $q = trim((string) $request->query('q'));
        $preset = array_key_exists($request->query('preset', 'all'), self::PRESETS) ? $request->query('preset', 'all') : 'all';
        $sort = array_key_exists($request->query('sort', 'dl'), self::SORTS) ? $request->query('sort', 'dl') : 'dl';
        $kind = Kind::tryFrom((string) $request->query('kind'));
        $pending = $purchase->cars()->where(fn ($w) => $w->whereIn('specs_state', ['pending', 'running'])->orWhereIn('photos_state', ['pending', 'running']))->count();

        // Числа — один проход по лёгкой выборке всей закупки, без поиска; перекрёстные: у каждого
        // фильтра число считается при двух других применённых, так они сходятся между собой.
        $all = $purchase->cars()->with('offers:id,car_id,user_id,state')->get(['id', 'kind', 'price_final', 'photos_count', 'is_published', 'specs_state', 'photos_state']);
        $managers = User::where('role', Role::Manager)->orWhereIn('id', $all->flatMap(fn ($c) => $c->activeOfferList()->pluck('user_id'))->unique())->get();
        $user = $managers->firstWhere('id', (int) $request->query('user'));
        $match = [
            'all' => fn ($c) => true,
            'priced' => fn ($c) => $c->activeOfferList()->isNotEmpty(),
            'unpriced' => fn ($c) => $c->activeOfferList()->isEmpty(),
            'unfinal' => fn ($c) => $c->price_final === null,
            'final' => fn ($c) => $c->price_final !== null,
            'attention' => fn ($c) => $c->specs_state->needsAttention() || $c->photos_state->needsAttention(),
            'nophoto' => fn ($c) => $c->photos_count === 0,
            'hidden' => fn ($c) => ! $c->is_published,
        ];
        $byKind = fn ($c) => ! $kind || $c->kind === $kind;
        $byUser = fn ($c) => ! $user || $c->activeOfferList()->contains('user_id', $user->id);
        $counts = array_map(fn ($f) => $all->filter($byKind)->filter($byUser)->filter($f)->count(), $match);
        // Типы — все, что есть в закупке, число — при выбранных состоянии и менеджере (бывает 0: тип не пропадает, его можно снять).
        $kinds = $all->groupBy(fn ($c) => $c->kind->value)->map(fn ($g) => $g->filter($match[$preset])->filter($byUser)->count())->all();
        $offered = $managers->mapWithKeys(fn ($u) => [$u->id => $all->filter($byKind)->filter($match[$preset])->filter(fn ($c) => $c->activeOfferList()->contains('user_id', $u->id))->count()]);
        $managers = $managers->sortBy([fn ($a, $b) => $offered[$b->id] <=> $offered[$a->id], fn ($a, $b) => strcmp($a->name, $b->name)])->values();

        $live = fn ($o) => $o->whereIn('state', [OfferState::Active, OfferState::Chosen]);
        $cars = $purchase->cars()->with(['brand', 'model', 'settlement', 'media', 'offers.user'])
            ->when($q !== '', $this->search($q))
            ->when($kind, fn ($c) => $c->where('kind', $kind))
            ->when($user, fn ($c) => $c->whereHas('offers', fn ($o) => $live($o)->where('user_id', $user->id)));
        match ($preset) {
            'priced' => $cars->whereHas('offers', $live),
            'unpriced' => $cars->whereDoesntHave('offers', $live),
            'unfinal' => $cars->whereNull('price_final'),
            'final' => $cars->whereNotNull('price_final'),
            'attention' => $cars->where(fn ($w) => $w->whereIn('specs_state', ['failed', 'partial', 'gone'])->orWhereIn('photos_state', ['failed', 'partial', 'gone'])),
            'nophoto' => $cars->where('photos_count', 0),
            'hidden' => $cars->where('is_published', false),
            default => null,
        };
        match ($sort) {
            'best' => $cars->withMax(['offers as top_offer' => $live], 'amount')->orderByDesc('top_offer')->orderBy('dl'),
            'final' => $cars->orderByRaw('price_final desc nulls last')->orderBy('dl'),
            'fresh' => $cars->orderByDesc('id'),
            default => $cars->orderBy('dl'),
        };

        $cars = $cars->paginate(50)->withQueryString();
        // ?peek=ref (или first) — открыть окошко этой строки сразу: так «Оценить» ведёт в таблицу.
        $peek = $request->query('peek') ? $cars->first(fn ($c) => $request->query('peek') === 'first' || (string) $c->ref === (string) $request->query('peek')) : null;

        return view('admin.purchases.show', [
            'purchase' => $purchase, 'q' => $q, 'pending' => $pending, 'transitions' => array_filter(PurchaseState::cases(), fn ($s) => $s !== $purchase->state),
            'cars' => $cars, 'preset' => $preset, 'sort' => $sort, 'peek' => $peek ? 'car-'.$peek->id : null,
            'counts' => $counts, 'kind' => $kind, 'kinds' => $kinds, 'managers' => $managers, 'offered' => $offered, 'user' => $user,
        ]);
    }

    private function search(string $q): \Closure
    {
        return fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(dl) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(brand_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(model_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('vin', 'like', '%'.strtoupper($q).'%'));
    }

    public function update(Request $request, Purchase $purchase)
    {
        $purchase->update($request->validate(['title' => ['nullable', 'string', 'max:120'], 'supplier' => ['nullable', 'string', 'max:80'], 'offers_close_at' => ['nullable', 'date'], 'hide_priced' => ['sometimes', 'boolean']]));

        return back()->with('toast', 'Сохранено');
    }

    public function extend(Request $request, Purchase $purchase)
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:15,60']])['minutes'];
        $from = $purchase->offers_close_at?->isFuture() ? $purchase->offers_close_at : now();
        $purchase->update(['offers_close_at' => $from->addMinutes($minutes)]);

        return back()->with('toast', 'Приём до '.$purchase->offers_close_at->translatedFormat('j M, H:i'));
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
        return redirect("/purchases/{$purchase->number}/import?".http_build_query(['path' => $path]));
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

        return redirect("/purchases/{$purchase->number}")->with('toast', "Новых {$result['created']}, обновлено {$result['updated']}".($result['skipped'] ? ", пропущено {$result['skipped']}" : ''));
    }

    /** Единственная выгрузка: файл Carcade с нашей ценой, галки — что ещё в него положить; Excel или PDF. */
    public function export(Request $request, Purchase $purchase, Export $export)
    {
        $data = $request->validate([
            'parts' => 'required_unless:format,dl|array',
            'parts.*' => Rule::in(array_keys(Export::PARTS)),
            'format' => 'required|in:xlsx,pdf,dl',
        ]);
        $format = $data['format'];
        $ext = $format === 'pdf' ? 'pdf' : 'xlsx';
        $path = storage_path("app/private/purchases/{$purchase->id}/vygruzka-".now()->format('Ymd-His').'.'.$ext);
        @mkdir(dirname($path), 0775, true);
        try {
            match ($format) {
                'dl' => $export->short($purchase, $path),
                'pdf' => $export->pdf($export->tables($purchase, $data['parts']), $purchase, $path),
                default => $export->xlsx($purchase, $data['parts'], $path),
            };
        } catch (\RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['file' => $e->getMessage()]);
        }

        return $this->file($request, $path, "zakupka-{$purchase->number}".($format === 'dl' ? '-ceny' : '').".{$ext}");
    }

    /** В приложении на телефоне файл открывается во встроенном браузере — там нужен inline, иначе Quick Look без выхода. */
    private function file(Request $request, string $path, string $name)
    {
        return response()->download($path, $name, [], $request->boolean('inline') ? 'inline' : 'attachment')->deleteFileAfterSend();
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

    /** Окошко строки таблицы: фото, факты, цены Carcade, предложения менеджеров, наша цена. */
    public function peek(Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $car->load(['brand', 'model', 'settlement', 'media', 'offers.user']);

        return view('admin.purchases.peek', ['purchase' => $purchase, 'car' => $car]);
    }

    public function updateCar(Request $request, Purchase $purchase, Car $car, UpdateCar $update)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $data = $request->validate([
            'brand_id' => ['nullable', 'exists:brands,id'], 'model_id' => ['nullable', 'exists:car_models,id'], 'year' => ['nullable', 'integer'], 'vin' => ['nullable', 'string', 'max:17'],
            'mileage' => ['nullable', 'string'], 'transmission' => ['nullable', 'string'], 'fuel' => ['nullable', 'string'], 'engine_volume' => ['nullable', 'string'], 'engine_power' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:32'], 'settlement_id' => ['nullable', 'exists:settlements,id'], 'address' => ['nullable', 'string', 'max:255'], 'kind' => ['required', Rule::enum(Kind::class)],
            'price_revalued' => ['nullable', 'string'], 'price_listing' => ['nullable', 'string'], 'price_final' => ['nullable', 'string'], 'description' => ['nullable', 'string', 'max:5000'], 'condition' => ['nullable', 'string', 'max:120'],
        ]);
        foreach (['mileage', 'engine_volume', 'engine_power', 'price_revalued', 'price_listing', 'price_final'] as $n) {
            $data[$n] = isset($data[$n]) && $data[$n] !== '' ? (int) preg_replace('/\D+/', '', $data[$n]) : null;
        }
        $data['transmission'] = $data['transmission'] ?: null;
        $data['fuel'] = $data['fuel'] ?: null;
        $data['is_published'] = $request->boolean('is_published');
        $data['share_locked'] = $request->boolean('share_locked');
        $update($car, $data);

        return back()->with('toast', 'Сохранено');
    }

    /** Наша цена из окошка строки («Дальше»): пустое поле ничего не трогает; окошко переходит к следующей без цены само. */
    public function estimate(Request $request, Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $raw = $request->validate(['price_final' => ['nullable', 'string', 'max:20']])['price_final'] ?? '';
        if ($raw !== '') {
            $car->update(['price_final' => (int) preg_replace('/\D+/', '', $raw) ?: null]);
        }

        return redirect("/purchases/{$purchase->number}?preset=unfinal")->with('peek-advance', true);
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
