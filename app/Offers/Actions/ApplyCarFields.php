<?php

namespace App\Offers\Actions;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Identity;
use App\Cars\Papers;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Cars\Vin\Vin;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Поля машины в карточку предложения по правилам формы (`OfferRequest`): VIN заглавными, цвет словарём, марка и
 * модель по справочнику, город по справочнику городов; сменили марку без модели — модель прежней марки снимается.
 * Характеристики ложатся только в пустые поля; с `$onlyEmpty` — и марка, модель, VIN, год, цвет (лот Мигторга
 * дописывает, а «✨ Из документов» правит то, что человек выбрал). Не прошедшее правила поле пропускается.
 * `$chosen` — поле → ['value' => …], как его отдаёт окно «✨».
 */
final class ApplyCarFields
{
    /** Только в пустые — всегда: документ или лот их знает, но вписанное человеком важнее. */
    private const SPECS = ['mileage', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'settlement_id', 'body', 'has_keys', 'papers', 'insurer_deadline_at', 'answer_by'];

    /** @return list<string> поля, которые записаны */
    public function __invoke(Offer $offer, array $chosen, ?User $by, bool $onlyEmpty = false, array $log = []): array
    {
        $data = [];
        foreach ($chosen as $field => $item) {
            $value = $item['value'];
            match ($field) {
                // Новую марку заводит только источник со своим справочником марок (`create`: Мигторг) — документы и письма нет.
                'brand' => ($id = (! empty($item['create']) ? Brand::resolve((string) $value) : Brand::known((string) $value))?->id) ? $data['brand_id'] = $id : null,
                'model' => null,
                'vin' => ($vin = Vin::full((string) $value)) ? $data['vin'] = $vin : null,
                'color' => $data['color'] = Colors::normalize((string) $value) ?? (string) $value,
                'year' => $data['year'] = (int) $value,
                'mileage', 'engine_volume', 'engine_power' => $data[$field] = (int) $value,
                'transmission', 'drive', 'fuel', 'body', 'papers' => $data[$field] = (string) $value,
                'has_keys' => $data['has_keys'] = (bool) $value,
                'city' => ($id = Settlement::named((string) $value)['id'] ?? null) ? $data['settlement_id'] = $id : null,
                'deadline' => [$data['insurer_deadline_at'], $data['answer_by']] = [Carbon::parse($value)->toDateString(), Carbon::parse($value)],
                default => null,
            };
        }
        $keep = $onlyEmpty ? [...self::SPECS, 'brand_id', 'vin', 'year', 'color'] : self::SPECS;
        foreach ($keep as $field) {
            if (array_key_exists($field, $data) && ! blank($offer->{$field})) {
                unset($data[$field]);
            }
        }
        $brandId = $data['brand_id'] ?? $offer->brand_id;
        // Марки документа нет в справочнике — его модель не заводится под прежней маркой («Kia H5» из документа Hongqi).
        $chosenBrand = isset($chosen['brand']) ? Brand::known((string) $chosen['brand']['value']) : null;
        $model = ! (isset($chosen['brand']) && ! $chosenBrand) && isset($chosen['model']['value']);
        if ($onlyEmpty) {
            // Модель лота — только под его же маркой («Solaris» не заводится под Kia) и только в пустое.
            $model = $model && $chosenBrand?->id === $brandId && (! $offer->model_id || $brandId !== $offer->brand_id);
        }
        if ($model && ($brand = Brand::find($brandId))) {
            $data['model_id'] = CarModel::resolve($brand, (string) $chosen['model']['value'])->id;
        } elseif ($brandId !== $offer->brand_id) {
            $data['model_id'] = null;
        }
        $errors = Validator::make($data, array_intersect_key([
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model_id' => ['nullable', 'exists:car_models,id'],
            'year' => ['integer', 'between:1950,'.(now()->year + 1)],
            'vin' => ['string', 'size:17'],
            'color' => ['string', 'max:32'],
            'mileage' => ['integer', 'between:1,5000000'],
            'transmission' => [Rule::enum(Transmission::class)],
            'drive' => [Rule::enum(Drive::class)],
            'fuel' => [Rule::enum(Fuel::class)],
            'body' => [Rule::enum(Body::class)],
            'papers' => [Rule::enum(Papers::class)],
            'has_keys' => ['boolean'],
            'engine_volume' => ['integer', 'between:1,20000'],
            'engine_power' => ['integer', 'between:1,3000'],
            'settlement_id' => ['exists:settlements,id'],
            'insurer_deadline_at' => ['date'],
            'answer_by' => ['date'],
        ], $data))->errors();
        $data = array_diff_key($data, array_flip($errors->keys()));
        // VIN другого живого предложения — не этой машины (одна машина — одна запись, `Identity`): не пишем, в историю.
        if (isset($data['vin']) && ($holder = Identity::offerByVin($data['vin'], $offer->id))) {
            $offer->log(OfferEventType::Note, $by, ['text' => "VIN {$data['vin']} уже у ".Identity::offerName($holder).', не записан']);
            unset($data['vin']);
        }
        if ($data) {
            app(UpdateOffer::class)($offer, $data, $by, $log);
        }

        return array_keys($data);
    }
}
