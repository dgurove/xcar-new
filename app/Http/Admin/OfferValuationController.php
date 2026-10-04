<?php

namespace App\Http\Admin;

use App\Offers\Actions\MatchValuations;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\ValuationText;
use App\Park\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * «Оценить → Из текста» во вкладке «Без цены» (владелец, 05.10.2026): модератор вставляет сообщение с оценочными
 * стоимостями, шторка показывает разбор этапами (`valuation_controller`) и что получится, «Всё верно, сохранить»
 * пишет оценочные и закупочные. Разобранное лежит в кэше под ключом — сохраняется то, что человек видел, а не то,
 * что прислал браузер.
 */
final class OfferValuationController
{
    public function preview(Request $request, MatchValuations $match)
    {
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:100000']])['text'];
        $parsed = ValuationText::parse($text);
        $items = $match($request->user(), $parsed['rows']);
        $token = Str::random(32);
        $take = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'check'], true));
        Cache::put("valuation:{$token}", [
            'user' => $request->user()->id,
            'amounts' => $take->mapWithKeys(fn ($i) => [$i['offer']->id => $i['amount']])->all(),
            // Что вписано иначе: без «Перезаписать» это поле остаётся прежним.
            'differs' => $take->filter(fn ($i) => $i['status'] === 'conflict')->mapWithKeys(fn ($i) => [$i['offer']->id => ['value' => $i['value_differs'], 'floor' => $i['floor_differs']]])->all(),
        ], now()->addMinutes(30));
        // Строки текста для анимации «убираем лишнее»: остаются номер и сумма, прочее схлопывается.
        $keep = collect($parsed['rows'])->flatMap(fn ($r) => $r['lines'])->flip();
        $lines = collect($parsed['lines'])->map(fn ($l, $i) => ['text' => trim($l), 'keep' => $keep->has($i)])
            ->filter(fn ($l) => $l['text'] !== '')->take(400)->values();
        $by = collect($items)->countBy('status');

        return response()->json([
            'token' => $token,
            'lines' => $lines,
            'counts' => [
                'refs' => count($parsed['rows']),
                'found' => collect($items)->filter(fn ($i) => $i['offer'])->unique(fn ($i) => $i['offer']->id)->count(),
                'missing' => $by['missing'] ?? 0,
                'take' => $take->where('status', '!=', 'check')->count(),
                'conflicts' => $by['conflict'] ?? 0,
            ],
            'html' => view('admin.offers.valuation-rows', ['items' => $items])->render(),
        ]);
    }

    public function apply(Request $request, UpdateOffer $update)
    {
        $data = $request->validate(['token' => ['required', 'string'], 'offers' => ['array'], 'offers.*' => ['integer'], 'keep' => ['array'], 'keep.*' => ['in:keep,overwrite']]);
        $saved = Cache::pull("valuation:{$data['token']}");
        if (! $saved || $saved['user'] !== $request->user()->id) {
            return back()->with('toast', 'Разбор устарел, вставьте текст ещё раз');
        }
        $ids = array_values(array_intersect(array_map('intval', $data['offers'] ?? []), array_keys($saved['amounts'])));
        $done = 0;
        foreach (Offer::query()->inCrm($request->user())->whereKey($ids)->get() as $offer) {
            $value = (int) $saved['amounts'][$offer->id];
            $floor = Sale::floorFrom($value);
            $set = ['value' => $value, 'floor_price' => $floor];
            // Расхождение без «Перезаписать» — вписанное остаётся; оставили оценочную — закупочная тоже прежняя.
            $differs = $saved['differs'][$offer->id] ?? null;
            if ($differs && ($data['keep'][$offer->id] ?? 'keep') === 'keep') {
                $set = $differs['value'] ? [] : array_diff_key($set, $differs['floor'] ? ['floor_price' => 1] : []);
            }
            if ($set === []) {
                continue;
            }
            $update($offer, $set, $request->user(), ['source' => 'valuation', 'value' => $value, 'floor' => $set['floor_price'] ?? null]);
            $done++;
        }

        return back()->with('toast', $done ? 'Оценено '.$done : 'Ничего не выбрано');
    }
}
