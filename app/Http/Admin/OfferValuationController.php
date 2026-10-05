<?php

namespace App\Http\Admin;

use App\Cars\Identity;
use App\Cars\Settlement;
use App\Cars\Vin\Vin;
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
 * пишет оценочные и закупочные, а с ними VIN (в пустой) и город (текст главнее) — 05.10.2026: на Мигторге VIN нет. Чего
 * нет ни у предложения, ни в тексте, вписывают тут же (`vin[id]`, `city[id]`). Разобранное лежит в кэше под ключом —
 * сохраняется то, что человек видел, а не то, что прислал браузер.
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
        $found = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'same'], true));
        Cache::put("valuation:{$token}", [
            'user' => $request->user()->id,
            // VIN и город из текста — по предложению, и у тех, где сумма та же.
            'vin' => $found->filter(fn ($i) => $i['vin_fill'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['vin_fill']])->all(),
            'city' => $found->filter(fn ($i) => $i['city_fill'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['city_fill']['id']])->all(),
            // Строки с галкой: снятая — ничего не пишем.
            'checkable' => $found->filter(fn ($i) => $i['status'] === 'fill')->map(fn ($i) => $i['offer']->id)->values()->all(),
            // Кому можно вписать руками: чего нет ни у предложения, ни в тексте.
            'gaps' => $found->filter(fn ($i) => $i['gaps'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['gaps']])->all(),
            'amounts' => $take->mapWithKeys(fn ($i) => [$i['offer']->id => $i['amount']])->all(),
            // Что вписано иначе: без «Перезаписать» это поле остаётся прежним.
            'differs' => $take->filter(fn ($i) => $i['status'] === 'conflict')->mapWithKeys(fn ($i) => [$i['offer']->id => ['value' => $i['value_differs'], 'floor' => $i['floor_differs']]])->all(),
        ], now()->addMinutes(30));
        // Строки текста для анимации «убираем лишнее»: остаются номер, сумма, VIN и город, прочее схлопывается.
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
                'no_vin' => $found->filter(fn ($i) => in_array('vin', $i['gaps'], true))->unique(fn ($i) => $i['offer']->id)->count(),
                'no_city' => $found->filter(fn ($i) => in_array('city', $i['gaps'], true))->unique(fn ($i) => $i['offer']->id)->count(),
            ],
            'html' => view('admin.offers.valuation-rows', ['items' => $items])->render(),
        ]);
    }

    public function apply(Request $request, UpdateOffer $update)
    {
        $data = $request->validate(['token' => ['required', 'string'], 'offers' => ['array'], 'offers.*' => ['integer'], 'keep' => ['array'], 'keep.*' => ['in:keep,overwrite'],
            'vin' => ['array'], 'vin.*' => ['nullable', 'string', 'max:20'], 'city' => ['array'], 'city.*' => ['nullable', 'integer']]);
        $saved = Cache::pull("valuation:{$data['token']}");
        if (! $saved || $saved['user'] !== $request->user()->id) {
            return back()->with('toast', 'Разбор устарел, вставьте текст ещё раз');
        }
        $ids = array_values(array_intersect(array_map('intval', $data['offers'] ?? []), array_keys($saved['amounts'])));
        // Вписанное руками — только там, где его не было ни у предложения, ни в тексте (`gaps`), и только годное.
        $typedVin = collect($data['vin'] ?? [])->map(fn ($v) => Vin::full((string) $v))
            ->filter(fn ($v, $id) => $v && in_array('vin', $saved['gaps'][$id] ?? [], true));
        $typedCity = collect($data['city'] ?? [])->filter(fn ($v, $id) => $v && in_array('city', $saved['gaps'][$id] ?? [], true) && Settlement::whereKey($v)->exists());
        $vins = ($saved['vin'] ?? []) + $typedVin->all();
        $cities = ($saved['city'] ?? []) + $typedCity->map(fn ($v) => (int) $v)->all();
        // Строку с галкой сняли — с неё не пишется ничего, и VIN с городом тоже.
        $off = array_diff($saved['checkable'] ?? [], $ids);
        $vins = array_diff_key($vins, array_flip($off));
        $cities = array_diff_key($cities, array_flip($off));
        $touch = array_values(array_unique([...$ids, ...array_keys($vins), ...array_keys($cities)]));
        $done = $vinDone = $cityDone = $vinTaken = 0;
        foreach (Offer::query()->inCrm($request->user())->whereKey($touch)->get() as $offer) {
            $set = [];
            if (in_array($offer->id, $ids, true)) {
                $value = (int) $saved['amounts'][$offer->id];
                $set = ['value' => $value, 'floor_price' => Sale::floorFrom($value)];
                // Расхождение без «Перезаписать» — вписанное остаётся; оставили оценочную — закупочная тоже прежняя.
                $differs = $saved['differs'][$offer->id] ?? null;
                if ($differs && ($data['keep'][$offer->id] ?? 'keep') === 'keep') {
                    $set = $differs['value'] ? [] : array_diff_key($set, $differs['floor'] ? ['floor_price' => 1] : []);
                }
                // «Уже так» по сумме, а дописывается VIN или город — оценкой не считается.
                (isset($set['value']) && (int) $offer->value !== $set['value']) || (isset($set['floor_price']) && (int) $offer->floor_price !== $set['floor_price']) ? $done++ : null;
            }
            // VIN — только в пустой (другой — перепроверить); город из текста главнее (Мигторг бывает устаревшим).
            // VIN другого живого предложения не встанет (`Identity`) — строка без него, в тосте «VIN занят».
            if (isset($vins[$offer->id]) && blank($offer->vin)) {
                if (Identity::offerByVin($vins[$offer->id], $offer->id)) {
                    $vinTaken++;
                } else {
                    $set['vin'] = $vins[$offer->id];
                    $vinDone++;
                }
            }
            if (isset($cities[$offer->id]) && $cities[$offer->id] !== $offer->settlement_id) {
                $set['settlement_id'] = $cities[$offer->id];
                $cityDone++;
            }
            if ($set === []) {
                continue;
            }
            $update($offer, $set, $request->user(), ['source' => 'valuation', 'value' => $set['value'] ?? null, 'floor' => $set['floor_price'] ?? null]);
        }
        $parts = array_filter([$done ? 'оценено '.$done : null, $vinDone ? 'VIN '.$vinDone : null, $cityDone ? 'город '.$cityDone : null, $vinTaken ? 'VIN занят другим предложением: '.$vinTaken : null]);

        return back()->with('toast', $parts ? Str::ucfirst(implode(', ', $parts)) : 'Ничего не выбрано');
    }
}
