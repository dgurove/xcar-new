<?php

namespace App\Http\Admin;

use App\Offers\Actions\ApplyValuations;
use App\Offers\Actions\MatchValuations;
use App\Offers\ValuationPhoto;
use App\Offers\ValuationSheet;
use App\Offers\ValuationText;
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
    /**
     * Фото или скриншот сообщения (`ValuationPhoto`) или таблица Excel (`ValuationSheet`, 25.10.2026) → текст «номер
     * сумма»; `sure` — сразу в разбор, иначе на проверку.
     */
    public function file(Request $request)
    {
        $file = $request->validate(['file' => ['required', 'file', 'max:15360']])['file'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ValuationSheet::EXTENSIONS, true)) {
            try {
                return response()->json(ValuationSheet::read($file->getRealPath()));
            } catch (\Throwable) {
                return response()->json(['text' => '', 'sure' => false]);
            }
        }
        abort_unless(str_starts_with((string) $file->getMimeType(), 'image/'), 422, 'Нужна картинка или таблица');

        return response()->json(ValuationPhoto::read($file->getRealPath()));
    }

    public function preview(Request $request, MatchValuations $match)
    {
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:100000']])['text'];
        $parsed = ValuationText::parse($text);
        $items = $match($request->user(), $parsed['rows']);
        $token = Str::random(32);
        $take = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'check'], true));
        $found = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'same'], true));
        Cache::put("valuation:{$token}", ApplyValuations::prepare($request->user(), $items), now()->addMinutes(30));
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
                'absent' => ($by['absent'] ?? 0) + ($by['missing'] ?? 0),
                'take' => $take->where('status', '!=', 'check')->count(),
                'conflicts' => $by['conflict'] ?? 0,
                'no_vin' => $found->filter(fn ($i) => in_array('vin', $i['gaps'], true))->unique(fn ($i) => $i['offer']->id)->count(),
                'no_city' => $found->filter(fn ($i) => in_array('city', $i['gaps'], true))->unique(fn ($i) => $i['offer']->id)->count(),
            ],
            'html' => view('admin.offers.valuation-rows', ['items' => $items])->render(),
        ]);
    }

    public function apply(Request $request, ApplyValuations $apply)
    {
        $data = $request->validate(['token' => ['required', 'string'], 'offers' => ['array'], 'offers.*' => ['integer'], 'keep' => ['array'], 'keep.*' => ['in:keep,overwrite'],
            'create' => ['array'], 'create.*' => ['string'],
            'vin' => ['array'], 'vin.*' => ['nullable', 'string', 'max:20'], 'city' => ['array'], 'city.*' => ['nullable', 'integer']]);
        $saved = Cache::pull("valuation:{$data['token']}");
        if (! $saved || $saved['user'] !== $request->user()->id) {
            return $request->expectsJson() ? response()->json(['title' => 'Разбор устарел', 'sub' => 'Вставьте текст ещё раз'])
                : back()->with('toast', 'Разбор устарел, вставьте текст ещё раз');
        }
        ['done' => $done, 'repriced' => $repriced, 'created' => $created, 'vin' => $vinDone, 'city' => $cityDone, 'vin_taken' => $vinTaken]
            = $apply($request->user(), $saved, array_map('intval', $data['offers'] ?? []), $data['keep'] ?? [], $data['create'] ?? [], $data['vin'] ?? [], $data['city'] ?? []);
        $parts = array_filter([$done ? 'оценено '.$done : null, $repriced ? 'на переоценку '.$repriced : null, $created ? 'заведено '.count($created) : null, $vinDone ? 'VIN '.$vinDone : null, $cityDone ? 'город '.$cityDone : null, $vinTaken ? 'VIN занят другим предложением: '.$vinTaken : null]);
        // Окно (valuation_controller) показывает итог экраном «Готово»: крупно — оценено, ниже — остальное.
        if ($request->expectsJson()) {
            $rest = array_filter([$repriced ? 'на переоценку '.$repriced.' — у них уже есть подтверждения' : null, $done && $created ? 'заведено '.count($created) : null, $vinDone ? 'VIN '.$vinDone : null, $cityDone ? 'город '.$cityDone : null, $vinTaken ? 'VIN занят другим предложением: '.$vinTaken : null]);

            return response()->json([
                'title' => $done ? 'Оценено '.$done : ($created ? 'Заведено '.count($created) : ($repriced ? 'На переоценку '.$repriced : ($rest ? 'Сохранено' : 'Ничего не выбрано'))),
                'sub' => $rest ? Str::ucfirst(implode(', ', $rest)) : null,
                'created' => $created,
            ]);
        }

        return back()->with('toast', $parts ? Str::ucfirst(implode(', ', $parts)) : 'Ничего не выбрано');
    }
}
