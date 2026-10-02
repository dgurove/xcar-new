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

    /** Города для поля «Город» предложения: поиском, а не списком из тысячи восьмисот строк в каждом окошке. */
    public function settlements(Request $request)
    {
        $q = mb_strtolower(trim($request->query('q', '')));
        $towns = Settlement::query()
            ->when($q, fn ($s) => $s->whereRaw('lower(name) like ?', ["{$q}%"]))
            ->orderByDesc('is_federal_city')->orderBy('name')->limit(20)->get(['id', 'name']);

        return response()->json($towns->map(fn ($s) => ['id' => $s->id, 'label' => $s->name]));
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
