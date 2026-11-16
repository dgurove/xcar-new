<?php

namespace App\Http\Admin;

use App\Live\Rows;
use App\Offers\CarWindow;
use App\Offers\Offer;
use App\Offers\OfferFilters;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Галерея CRM (08.10.2026, владелец: «как в предложениях — просмотр опубликованных и откликов»): черновики в окне
 * показа (`Offer::inGallery`, `ShowInGallery`) — когда появились и сколько осталось; «Отклики» — у кого есть интерес,
 * кто и что — в карточке строки. Свой список, без вида плиток: CRM — таблица. С 16.11.2026 — раздел «Галерея» колеса
 * «Предложений» (владелец: «в галерею предложения попадают параллельно, пока они в черновиках»): вкладки `gallery` и
 * `interest` (`OfferController::PRESETS`), строки рисует `tab`; прежний адрес `/gallery` — 301 туда.
 */
class GalleryController
{
    public const PRESETS = ['gallery' => 'В галерее', 'interest' => 'Отклики'];

    /** Прежние ключи `/gallery?preset=` → вкладки колеса. */
    private const OLD = ['live' => 'gallery', 'interest' => 'interest'];

    public const SORTS = [
        'fresh' => ['Появилось', 'desc'], 'closing' => ['Осталось', 'asc'], 'interest' => ['Интерес', 'desc'],
        'last' => ['Последний отклик', 'desc'], 'number' => ['Номер', 'desc'],
    ];

    /** Прежний адрес «Галереи» (закладки, уведомления, «назад») — во вкладку колеса с теми же чипами, видом и строкой. */
    public function index(Request $request)
    {
        $query = ['preset' => self::OLD[(string) $request->query('preset')] ?? 'gallery'] + $request->except('preset');

        return redirect('/offers?'.str_replace('%2C', ',', http_build_query($query)), 301);
    }

    public function tab(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $preset = array_key_exists((string) $request->query('preset'), self::PRESETS) ? (string) $request->query('preset') : 'gallery';
        $searching = filled($request->query('q'));
        // Шапка вкладки (17.11.2026): все чипы и параметры машины, денег и сроков (`OfferFilters`) — в окне «Фильтр».
        $base = OfferController::facets();
        $facets = Facets::for($request, 'crm-gallery', ...$base, ...OfferFilters::for('offers.id', $request->user()->canManageCrm()))->saving('offers');
        ListPrefs::sync($request, 'crm-gallery', keep: array_values(array_diff($facets->keys(), OfferController::ONCE)));
        $sort = Sort::from($request->query('sort'), self::SORTS, $preset === 'interest' ? '-last' : '-fresh');

        $q = self::base();
        if ($searching) {
            $q->whereNotNull('offers.gallery_from')->searchCrm(trim((string) $request->query('q')));
        } else {
            self::scopeFor($preset, $q);
        }
        $facets->apply($q);
        // Число — до сортировки: «interest_at» есть только в выборке, а count() порядок оставляет.
        $total = (clone $q)->count();
        $dir = $sort->dir();
        match ($sort->key) {
            'closing' => $q->orderBy('gallery_until', $dir),
            'interest' => $q->orderBy('interests_count', $dir)->orderByDesc('gallery_from'),
            'last' => $q->orderByRaw("interest_at {$dir} nulls last"),
            'number' => $q->orderBy('number', $dir),
            default => $q->orderBy('gallery_from', $dir),
        };
        $q->orderByDesc('offers.id');
        Rows::offers($q);

        $user = $request->user();

        return view('admin.gallery.index', [
            'offers' => ListView::paginate($request, $q, $total),
            'preset' => $preset,
            // Колесо — вкладки «Предложений» с разделами, как у сделок и гаража.
            'presets' => OfferController::presets($user),
            'sections' => OfferController::sections($user),
            'counts' => OfferController::counts($user),
            'sort' => $sort,
            'facets' => $facets,
            'detail' => $detail,
            'searching' => $searching,
        ]);
    }

    public static function base(): Builder
    {
        return Offer::query()->with(['settlement', 'parkVehicle:id,car_id,state,accepted_at,created_at', 'deal', 'garageCar'])
            ->withCount('interests')->withMax('interests as interest_at', 'created_at');
    }

    /** «В галерее» — окно открыто; «Отклики» — побывавшие в галерее, у кого есть интерес (и после окна). */
    public static function scopeFor(string $preset, Builder $q): Builder
    {
        return $preset === 'interest'
            ? $q->whereNotNull('offers.gallery_from')->whereHas('interests')
            : $q->inGallery();
    }

    /** Таблица «Галереи» — столбцы одним описанием (`Support\Table`): шапка и строки `x-offer.gallery-row`. */
    public static function table(): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true),
            new Column('vendor', '№ убытка', from: 'sm', role: ColRole::Code, rem: 9),
            new Column('city', 'Город', from: 'sm', aside: true, rem: 6, clip: true, role: ColRole::Place),
            new Column('interest', 'Интерес', num: true, sort: 'interest', cell: 'nums', rem: 4.5),
            new Column('from', 'Появилось', from: 'sm', aside: true, sort: 'fresh', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 6.5),
            new Column('left', 'Осталось', from: 'sm', sort: 'closing', cell: 'whitespace-nowrap', role: ColRole::When, rem: 6),
            new Column('last', 'Отклик', from: 'sm', aside: true, sort: 'last', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 6.5),
        );
    }
}
