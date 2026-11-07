<?php

namespace App\Http\Admin;

use App\Cars\Car;
use App\Cars\Sources\Values;
use App\Cars\Sources\Variants;
use App\Http\Cars\CarFieldController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «Настройки → Парсинг» (08.11.2026, владелец: «отдельный раздел — видеть, что за машина, все расхождения по ней, удобный
 * выбор и по клику — в карточку сделки, предложения, заявки, машины»): все машины, у которых источники (Мигторг, письма,
 * документы — `Cars\Sources`) спорят, карточкой на машину — где она сейчас ссылками и спорные поля вариантами; нажатие на
 * вариант пишет его (`Pick`), на текущий — «оставить». Пилюли — по полю. Карточка — свой фрейм: после выбора перечитывается
 * только она. Только админ.
 */
final class ParsingController
{
    /** Порядок машин: где решение дороже — сделка, продажа, парковка, черновик. */
    private const RANK = ['deal' => 0, 'open' => 1, 'park' => 2, 'other' => 3];

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 404);
        $cars = $this->cars(Car::query()->where('value_conflicts', '>', 0));
        $counts = $cars->flatMap(fn ($c) => array_keys($c['variants']))->countBy()->sortDesc();
        $field = $counts->has($f = (string) $request->query('field')) ? $f : null;
        $shown = $field ? $cars->filter(fn ($c) => isset($c['variants'][$field])) : $cars;

        return view('admin.parsing.index', ['cars' => $shown->values(), 'total' => $cars->count(), 'counts' => $counts, 'field' => $field]);
    }

    /** Карточка одной машины — фреймом, после выбора. */
    public function car(Request $request, Car $car)
    {
        abort_unless($request->user()->isAdmin(), 404);

        // Фильтр по полю — как у списка: карточка после выбора показывает то же поле.
        $only = isset(Values::FIELDS[$f = (string) $request->query('field')]) ? $f : null;

        return view('admin.parsing.car', ['item' => $this->cars(Car::query()->whereKey($car->id))->first() ?? ['car' => $car, 'variants' => []], 'only' => $only]);
    }

    public function pick(Request $request, Car $car, string $field)
    {
        abort_unless($request->user()->isAdmin() && isset(Values::FIELDS[$field]), 404);
        $lane = Variants::all($car)[$field]['lane'] ?? $car->offer ?? $car->vehicle;
        abort_unless($lane, 404);

        return app(CarFieldController::class)->answer($request, $lane, $field);
    }

    /** @return Collection<int, array{car: Car, variants: array, rank: int}> */
    private function cars($query): Collection
    {
        return $query->with(['brand', 'model', 'offer.vendor', 'offer.deal', 'vehicle.vendor', 'vehicle.yard', 'vehicle.requests'])->get()
            ->map(fn (Car $car) => ['car' => $car, 'variants' => Variants::all($car), 'rank' => self::RANK[match (true) {
                (bool) $car->offer?->deal => 'deal',
                $car->offer?->state === \App\Offers\OfferState::Open => 'open',
                $car->vehicle && ! $car->vehicle->state->isFinal() => 'park',
                default => 'other',
            }]])
            ->filter(fn ($c) => $c['variants'])
            ->sortBy([['rank', 'asc'], fn ($a, $b) => $b['car']->id <=> $a['car']->id])
            ->values();
    }
}
