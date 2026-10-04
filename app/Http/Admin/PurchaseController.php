<?php

namespace App\Http\Admin;

use App\Purchases\Actions\ChangePurchaseState;
use App\Purchases\Actions\ChooseOffer;
use App\Purchases\Actions\CreatePurchase;
use App\Purchases\Actions\ImportFile;
use App\Purchases\Actions\MoveToOffers;
use App\Purchases\Actions\UnchooseOffer;
use App\Purchases\Actions\UpdateCar;
use App\Purchases\Car;
use App\Purchases\CounterReader;
use App\Purchases\Export;
use App\Purchases\Importer;
use App\Purchases\Kind;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Purchases\Restriction;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\OfficePreview;
use App\Support\Liters;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController
{
    public const PRESETS = ['all' => 'Все ТС', 'priced' => 'С предложениями', 'unpriced' => 'Без предложений', 'unfinal' => 'Без нашей цены', 'final' => 'С нашей ценой', 'attention' => 'Требуют внимания', 'nophoto' => 'Без фото', 'hidden' => 'Скрытые', 'moved' => 'В предложениях'];

    /** Пресеты группами в выборе «Все ТС ▾»: из каждой группы выбирают один ответ. */
    public const PRESET_GROUPS = ['' => ['all'], 'Предложения менеджеров' => ['priced', 'unpriced'], 'Наша цена' => ['unfinal', 'final'], 'Служебное' => ['attention', 'nophoto', 'hidden'], 'Контрпредложение' => ['moved']];

    public const SORTS = ['dl' => 'По порядку файла', 'best' => 'Лучшая цена', 'final' => 'Наша цена', 'fresh' => 'Сначала новые'];

    public function index()
    {
        return view('admin.purchases.index', [
            'purchases' => Purchase::withCount(['cars' => fn ($c) => $c->whereNull('offer_id')])->orderByDesc('number')->get(),
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
        // ?peek=ref — карточка ТС рядом с таблицей; first — первая без нашей цены (адрес получает её ref ниже).
        $detail = Detail::of($request, fn (string $key) => ($car = $purchase->cars()->where('ref', $key)->first()) ? $this->detail($purchase, $car) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $live = fn ($o) => $o->whereIn('state', [OfferState::Active, OfferState::Chosen]);
        // Числа чипов считаются ниже одним проходом по всей закупке — шторки берут их готовыми.
        $counts = [];
        $offered = collect();
        $facets = Facets::for($request, 'crm-purchase-cars',
            // Состояние — один выбор группами; применяется ниже своим match, сам чип запрос не трогает.
            Facet::custom('preset', 'Какие ТС', [], fn () => null, function () use (&$counts) {
                return $counts;
            })->single('all')->groups(self::PRESET_GROUPS)->natural()
                ->labels(fn (array $keys) => collect(self::PRESETS)->only($keys)->map(fn ($label, $key) => new Option($key, $label))->all()),
            Facet::custom('user', 'Менеджер', ['менеджер', 'менеджера', 'менеджеров'],
                fn ($c, array $ids) => $c->whereHas('offers', fn ($o) => $live($o)->whereIn('user_id', Common::ints($ids))),
                function () use (&$offered) {
                    return $offered->all();
                })->labels(fn (array $ids) => User::whereIn('id', Common::ints($ids))->with(User::withAvatar())->get()
                ->mapWithKeys(fn (User $u) => [(string) $u->id => new Option((string) $u->id, $u->name, user: $u)])->all()),
        );
        // Вид и сортировка помнятся одни на все закупки, чипы («Какие ТС», менеджер) — у каждой своя: ТС и цены у закупок разные.
        ListPrefs::sync($request, 'crm-purchase-cars');
        ListPrefs::sync($request, 'crm-purchase-'.$purchase->id, keep: $facets->keys(), view: false);
        $q = trim((string) $request->query('q'));
        $preset = $facets->selected('preset')[0] ?? 'all';
        $preset = array_key_exists($preset, self::PRESETS) ? $preset : 'all';
        $sort = array_key_exists($request->query('sort', 'dl'), self::SORTS) ? $request->query('sort', 'dl') : 'dl';
        $kind = Kind::tryFrom((string) $request->query('kind'));
        // Лупа — по всей закупке, мимо типа, состояния и менеджера.
        if ($q !== '') {
            [$preset, $kind] = ['all', null];
        }
        $users = $q !== '' ? [] : Common::ints($facets->selected('user'));
        $pending = $purchase->cars()->whereNull('offer_id')->where(fn ($w) => $w->whereIn('specs_state', ['pending', 'running'])->orWhereIn('photos_state', ['pending', 'running']))->count();

        // Числа — один проход по лёгкой выборке всей закупки, без поиска; перекрёстные: у каждого
        // фильтра число считается при двух других применённых, так они сходятся между собой.
        // ТС, ушедшие в предложения по контрпредложению, из закупки исключены — видны только в «В предложениях».
        $all = $purchase->cars()->with('offers:id,car_id,user_id,state')->get(['id', 'kind', 'price_final', 'photos_count', 'is_published', 'specs_state', 'photos_state', 'offer_id']);
        $managers = User::where('role', Role::Manager)->orWhereIn('id', $all->flatMap(fn ($c) => $c->activeOfferList()->pluck('user_id'))->unique())->get();
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
        $match = array_map(fn ($f) => fn ($c) => ! $c->offer_id && $f($c), $match) + ['moved' => fn ($c) => (bool) $c->offer_id];
        $byKind = fn ($c) => ! $kind || $c->kind === $kind;
        $byUser = fn ($c) => ! $users || $c->activeOfferList()->whereIn('user_id', $users)->isNotEmpty();
        $counts = array_map(fn ($f) => $all->filter($byKind)->filter($byUser)->filter($f)->count(), $match);
        // Типы — все, что есть в закупке, число — при выбранных состоянии и менеджере (бывает 0: тип не пропадает, его можно снять).
        $kinds = $all->groupBy(fn ($c) => $c->kind->value)->map(fn ($g) => $g->filter($match[$preset])->filter($byUser)->count())->all();
        $offered = $managers->mapWithKeys(fn ($u) => [(string) $u->id => $all->filter($byKind)->filter($match[$preset])->filter(fn ($c) => $c->activeOfferList()->contains('user_id', $u->id))->count()]);

        $cars = $purchase->cars()->with(['brand', 'model', 'settlement', 'media', 'offers.user', 'offer:id,number,state'])
            ->when($preset === 'moved', fn ($c) => $c->whereNotNull('offer_id'), fn ($c) => $c->whereNull('offer_id'))
            ->when($q !== '', $this->search($q))
            ->when($kind, fn ($c) => $c->where('kind', $kind));
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
        $facets->apply($cars);
        match ($sort) {
            'best' => $cars->withMax(['offers as top_offer' => $live], 'amount')->orderByDesc('top_offer')->orderBy('dl'),
            'final' => $cars->orderByRaw('price_final desc nulls last')->orderBy('dl'),
            'fresh' => $cars->orderByDesc('id'),
            default => $cars->orderBy('dl'),
        };

        $cars = ListView::paginate($request, $cars);
        // «Оценить» ведёт в таблицу: first — первая строка страницы.
        if (Detail::key($request) === 'first') {
            return redirect($request->fullUrlWithQuery(['peek' => $cars->first()?->ref]));
        }

        return view('admin.purchases.show', [
            'purchase' => $purchase, 'q' => $q, 'pending' => $pending, 'transitions' => array_filter(PurchaseState::cases(), fn ($s) => $s !== $purchase->state),
            'cars' => $cars, 'preset' => $preset, 'sort' => $sort, 'detail' => $detail,
            'counts' => $counts, 'kind' => $kind, 'kinds' => $kinds, 'facets' => $facets,
            // Один менеджер выбран — его цена в строке подсвечена.
            'highlight' => count($users) === 1 ? $users[0] : null,
            'vendors' => Vendor::where('is_active', true)->orWhere('id', $purchase->vendor_id)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    private function search(string $q): \Closure
    {
        return fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(dl) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(brand_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(model_raw) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('vin', 'like', '%'.strtoupper($q).'%'));
    }

    public function update(Request $request, Purchase $purchase)
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'vendor_id' => ['sometimes', 'nullable', 'exists:vendors,id'], 'offers_close_at' => ['nullable', 'date'], 'hide_priced' => ['sometimes', 'boolean']]);
        // Поставщик — вендор; подпись `supplier` идёт за ним, чтобы старые места показывали то же имя.
        if (array_key_exists('vendor_id', $data)) {
            $data['supplier'] = $data['vendor_id'] ? Vendor::find($data['vendor_id'])->name : null;
        }
        $purchase->update($data);

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

    // ---------------------------------------------------------------- контрпредложение

    /** Ответ поставщика: файл сохраняется и читается сразу — не прочитался, дальше предпросмотра не пускаем. */
    public function counterUpload(Request $request, Purchase $purchase, CounterReader $reader)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:20480']]);
        $path = $request->file('file')->storeAs("purchases/{$purchase->id}", 'counter-'.now()->format('Ymd-His').'.xlsx', 'private');
        try {
            $reader->read(Storage::disk('private')->path($path));
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect("/purchases/{$purchase->number}/counter?".http_build_query(['path' => $path]));
    }

    /** Что станет предложениями: строки файла, разложенные по тому, что с ними будет. */
    public function counterPreview(Request $request, Purchase $purchase, CounterReader $reader)
    {
        $path = $this->counterPath($purchase, (string) $request->query('path', ''));
        $rows = $reader->read(Storage::disk('private')->path($path));
        $cars = $purchase->cars()->with(['brand', 'model', 'media', 'offer:id,number'])->get()->keyBy(fn (Car $c) => mb_strtolower(trim($c->dl)));
        $groups = ['move' => [], 'moved' => [], 'unpriced' => [], 'missing' => []];
        foreach ($rows as $row) {
            $car = $cars[mb_strtolower(trim($row['dl']))] ?? null;
            $groups[match (true) {
                ! $car => 'missing',
                (bool) $car->offer_id => 'moved',
                ! $row['price'] => 'unpriced',
                default => 'move',
            }][] = $row + ['car' => $car];
        }

        return view('admin.purchases.counter', ['purchase' => $purchase->load('vendor'), 'path' => $path, 'groups' => $groups,
            'vendors' => Vendor::where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    public function counterMove(Request $request, Purchase $purchase, CounterReader $reader, MoveToOffers $move)
    {
        $path = $this->counterPath($purchase, (string) $request->validate(['path' => ['required', 'string']])['path']);
        $count = $move($purchase, $reader->read(Storage::disk('private')->path($path)), $request->user());

        return redirect('/?preset=draft&vid=table&peek=first')->with('toast', 'Черновиков: '.$count);
    }

    private function counterPath(Purchase $purchase, string $path): string
    {
        abort_unless(str_starts_with($path, "purchases/{$purchase->id}/counter-") && ! str_contains($path, '..') && Storage::disk('private')->exists($path), 404);

        return $path;
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
        // Имя с хвостом: шторка на телефоне просит файл и его вид одновременно — одна секунда, один путь, и первый
        // ответ удалял файл из-под второго.
        $path = storage_path("app/private/purchases/{$purchase->id}/vygruzka-".now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.'.$ext);
        @mkdir(dirname($path), 0775, true);
        try {
            match ($format) {
                'dl' => $export->short($purchase, $path),
                'pdf' => $export->pdf($export->tables($purchase, $data['parts']), $purchase, $path),
                default => $export->xlsx($purchase, $data['parts'], $path),
            };
        } catch (\RuntimeException $e) {
            // Шторка документов ждёт файл, а не страницу с ошибкой: ей — 422, она покажет «Скачать».
            return $request->expectsJson() || $request->boolean('preview') ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['file' => $e->getMessage()]);
        }
        $name = "zakupka-{$purchase->number}".($format === 'dl' ? '-ceny' : '').".{$ext}";
        // Excel в шторке документов — HTML-фрагментом.
        if ($request->boolean('preview') && $ext === 'xlsx') {
            try {
                return OfficePreview::response($path, $name);
            } finally {
                @unlink($path);
            }
        }

        return $this->file($request, $path, $name);
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

        return view('admin.purchases.car', ['purchase' => $purchase, 'car' => $car]);
    }

    /** Карточка строки таблицы: фото, факты, цены Carcade, предложения менеджеров, наша цена. */
    public function detail(Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $car->load(['brand', 'model', 'settlement', 'media', 'offers.user', 'offer:id,number']);

        return view('admin.purchases.detail', ['purchase' => $purchase, 'car' => $car]);
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
        $data['engine_volume'] = Liters::parse($data['engine_volume'] ?? null, $car->engine_volume);
        foreach (['mileage', 'engine_power', 'price_revalued', 'price_listing', 'price_final'] as $n) {
            $data[$n] = isset($data[$n]) && $data[$n] !== '' ? (int) preg_replace('/\D+/', '', $data[$n]) : null;
        }
        $data['transmission'] = $data['transmission'] ?: null;
        $data['fuel'] = $data['fuel'] ?: null;
        $data['is_published'] = $request->boolean('is_published');
        $data['share_locked'] = $request->boolean('share_locked');
        $update($car, $data);

        return back()->with('toast', 'Сохранено');
    }

    /** Наша цена из карточки строки («Дальше»): пустое поле ничего не трогает; карточка переходит к следующей без цены само. */
    public function estimate(Request $request, Purchase $purchase, Car $car)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $raw = $request->validate(['price_final' => ['nullable', 'string', 'max:20']])['price_final'] ?? '';
        if ($raw !== '') {
            $car->update(['price_final' => (int) preg_replace('/\D+/', '', $raw) ?: null]);
        }

        return redirect("/purchases/{$purchase->number}?preset=unfinal")->with('detail-advance', true);
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
