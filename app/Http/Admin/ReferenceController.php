<?php

namespace App\Http\Admin;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Transmission;
use App\Cars\Vin\VinAutofill;
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

    public function brands(Request $request)
    {
        $q = mb_strtolower(trim($request->query('q', '')));
        $brands = Brand::query()
            ->when($q, fn ($b) => $b->whereRaw('lower(name) like ?', ["{$q}%"])->orWhereRaw('lower(name_ru) like ?', ["{$q}%"]))
            ->orderByDesc('is_popular')->orderBy('name')->limit(20)->get();

        return response()->json($brands->map(fn ($b) => ['id' => $b->id, 'label' => $b->name, 'hint' => $b->name_ru]));
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
