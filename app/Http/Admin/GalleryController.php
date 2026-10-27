<?php

namespace App\Http\Admin;

use App\Live\Rows;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Support\Table\Column;
use App\Support\Table\Table;

/**
 * Галерея CRM (08.10.2026, владелец: «как в предложениях — просмотр опубликованных и откликов»): черновики в окне
 * показа (`Offer::inGallery`, `ShowInGallery`) — когда появились и сколько осталось; «Отклики» — у кого есть интерес,
 * кто и что — в карточке строки. Свой список, без вида плиток: CRM — таблица.
 */
class GalleryController
{
    public const PRESETS = ['live' => 'В галерее', 'interest' => 'Отклики'];

    public const SORTS = [
        'fresh' => ['Появилось', 'desc'], 'closing' => ['Осталось', 'asc'], 'interest' => ['Интерес', 'desc'],
        'last' => ['Последний отклик', 'desc'], 'number' => ['Номер', 'desc'],
    ];

    public function index(Request $request, OfferController $offers)
    {
        $detail = Detail::of($request, fn (string $key) => $offers->detailOf($request, OfferNumber::find($key), gallery: true));
        if ($detail->framed()) {
            return $detail->response();
        }
        $preset = array_key_exists((string) $request->query('preset'), self::PRESETS) ? (string) $request->query('preset') : 'live';
        $searching = filled($request->query('q'));
        $facets = Facets::for($request, 'crm-gallery', ...OfferController::facets());
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

        return view('admin.gallery.index', [
            'offers' => ListView::paginate($request, $q, $total),
            'preset' => $preset,
            'counts' => self::counts($facets),
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

    /** @return array<string, int> */
    public static function counts(?Facets $facets = null): array
    {
        return collect(self::PRESETS)->map(function ($label, $key) use ($facets) {
            $q = self::scopeFor($key, Offer::query());
            $facets?->applyTo($q);

            return $q->count();
        })->all();
    }

    /** Строка всё ещё в своей вкладке — для `OfferController::gone` (убрали из галереи, опубликовали). */
    public static function listed(?string $preset, ?Offer $offer): bool
    {
        return $offer && self::scopeFor(array_key_exists((string) $preset, self::PRESETS) ? $preset : 'live', Offer::whereKey($offer->id))->exists();
    }

    /** Таблица «Галереи» — столбцы одним описанием (`Support\Table`): шапка и строки `x-offer.gallery-row`. */
    public static function table(): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true),
            new Column('vendor', 'Вендор, № убытка', from: 'sm', rem: 9),
            new Column('city', 'Город', from: 'sm', aside: true, rem: 6),
            new Column('from', 'Появилось', from: 'sm', num: true, aside: true, sort: 'fresh', cell: 'nums whitespace-nowrap', rem: 6.5),
            new Column('left', 'Осталось', from: 'sm', fill: true, sort: 'closing', cell: 'whitespace-nowrap', rem: 6),
            new Column('interest', 'Интерес', num: true, sort: 'interest', cell: 'nums', rem: 4.5),
            new Column('last', 'Отклик', from: 'sm', num: true, aside: true, sort: 'last', cell: 'nums whitespace-nowrap !text-xs !text-ink-dim', rem: 6.5),
        );
    }
}
