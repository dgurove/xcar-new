<?php

namespace App\Http\Admin;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\CarText;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Cars\Vin\VinAutofill;
use App\Support\Liters;
use Illuminate\Http\Request;

/** Подсказки комбобоксов и создание записей справочника на лету. */
class ReferenceController
{
    /** Разбор VIN: что удалось вывести — значения полей и слова для сообщения. */
    public function vin(Request $request, VinAutofill $autofill)
    {
        $guess = $autofill->suggest((string) $request->query('vin', ''));
        $values = $guess['values'];
        if (isset($values['brand_id'])) {
            $values['brand'] = Brand::find($values['brand_id'])?->name;
        }
        if (isset($values['model_id'])) {
            $values['model'] = CarModel::find($values['model_id'])?->name;
        }
        if (isset($values['transmission'])) {
            $values['transmission_label'] = Transmission::from($values['transmission'])->label();
        }
        if (isset($values['drive'])) {
            $values['drive_label'] = Drive::from($values['drive'])->label();
        }
        if (isset($values['fuel'])) {
            $values['fuel_label'] = Fuel::from($values['fuel'])->label();
        }
        if (isset($values['body'])) {
            $values['body_label'] = Body::from($values['body'])->label();
        }

        return response()->json(['valid' => $guess['result']->valid, 'values' => $values, 'filled' => $guess['filled'], 'skipped' => $guess['skipped']]);
    }

    /**
     * Текст про машину кучей («+ Новый», вставка из WhatsApp) — значения полей формы (`CarText`); объём — литрами,
     * как в поле, у списков — подписи для сообщения. Текст едет телом POST: в нём телефоны и имена.
     */
    public function carText(Request $request)
    {
        $values = CarText::parse($request->validate(['text' => ['required', 'string', 'max:5000']])['text']);
        if (isset($values['engine_volume'])) {
            $values['engine_volume'] = Liters::format($values['engine_volume']);
        }

        return response()->json(['values' => $values]);
    }

    public function brands(Request $request)
    {
        $q = mb_strtolower(trim($request->query('q', '')));
        $brands = Brand::query()
            ->when($q, fn ($b) => $b->whereRaw('lower(name) like ?', ["{$q}%"])->orWhereRaw('lower(name_ru) like ?', ["{$q}%"]))
            ->orderByDesc('is_popular')->orderBy('name')->limit(20)->get();

        return response()->json($brands->map(fn ($b) => ['id' => $b->id, 'label' => $b->name, 'hint' => $b->name_ru]));
    }

    /**
     * Поле «Город» (предложение, парковка, закупка): поиском по началу имени среди 155 тысяч мест ОКТМО — сначала
     * города, потом посёлки, сёла, деревни. Число в запросе — номер региона («никольское 47», «Москва, 77»
     * или просто «77» — тогда первыми крупные места региона). Подпись — «Серпухов, 50»,
     * ниже район и регион: одноимённых мест бывают сотни.
     */
    public function settlements(Request $request)
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $plate = preg_match('/(?:^|[\s,])(\d{1,3})$/u', $q, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : null;
        $q = trim((string) preg_replace('/(?:^|[\s,])\d{1,3}$/u', '', $q), " ,");
        $places = Settlement::query()->with('region:id,short')
            // Место-регион («Республика Татарстан», тип `рег`) находится и по второму слову: ищут «татарстан».
            ->when($q, fn ($s) => $s->where(fn ($w) => $w->whereRaw('lower(name) like ?', [str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'])
                ->orWhere(fn ($r) => $r->where('type', 'рег')->whereRaw('lower(name) like ?', ['% '.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%']))))
            ->when($plate, fn ($s) => $s->where('region_code', $plate))
            ->orderBy('rank')->orderBy('name')->limit(20)->get(['id', 'name', 'type', 'region_code', 'region_id', 'district']);

        return response()->json($places->map(fn (Settlement $s) => [
            'id' => $s->id,
            'label' => $s->title(),
            'hint' => $s->type === 'рег' ? 'регион' : implode(', ', array_filter([$s->districtShort(), $s->region?->short])),
        ]));
    }

    /**
     * Место, которого нет в справочнике (владелец 06.10.2026: «для крайне редких случаев»), — свободным текстом: строкой
     * справочника с типом `своб`, чтобы поле осталось ссылкой и находилось в следующий раз. Номер в конце — регион.
     */
    public function createSettlement(Request $request)
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $request->validate(['name' => ['required', 'string', 'max:120']])['name']), ' ,');
        $plate = preg_match('/[\s,]+(\d{1,3})$/u', $name, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : null;
        $name = $plate ? trim((string) preg_replace('/[\s,]+\d{1,3}$/u', '', $name)) : $name;
        $region = $plate ? \App\Cars\Region::where('plate', $plate)->first() : null;
        $place = Settlement::firstOrCreate(
            ['name' => $name, 'type' => 'своб', 'region_code' => $region?->plate],
            ['region_id' => $region?->id, 'rank' => 9],
        );

        return response()->json(['id' => $place->id, 'label' => $place->title()]);
    }

    /** Менеджеры по имени или телефону — «Подтверждение за менеджера». */
    public function managers(Request $request)
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $digits = preg_replace('/\D+/', '', $q);
        $users = \App\Users\User::withRole(\App\Users\Role::Manager)
            ->when($q, fn ($u) => $u->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'])
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%'))))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone']);

        return response()->json($users->map(fn ($u) => ['id' => $u->id, 'label' => $u->name, 'hint' => $u->phone ? $u->phoneFormatted() : null]));
    }

    public function models(Request $request)
    {
        $q = mb_strtolower(trim($request->query('q', '')));
        $models = CarModel::query()->where('brand_id', $request->query('brand'))
            ->when($q, fn ($b) => $b->whereRaw('lower(name) like ?', ["{$q}%"]))
            ->orderBy('name')->limit(30)->get();

        return response()->json($models->map(fn ($m) => ['id' => $m->id, 'label' => $m->name, 'hint' => $m->name_ru]));
    }

    public function createBrand(Request $request)
    {
        $brand = Brand::resolve($request->validate(['name' => ['required', 'string', 'max:120']])['name']);

        return response()->json(['id' => $brand->id, 'label' => $brand->name]);
    }

    public function createModel(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'brand' => ['required', 'exists:brands,id']]);
        $model = CarModel::resolve(Brand::findOrFail($data['brand']), $data['name']);

        return response()->json(['id' => $model->id, 'label' => $model->name]);
    }
}
