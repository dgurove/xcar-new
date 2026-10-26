<?php

namespace App\Cars\Actions;

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
use App\Mail\Extraction\Patterns;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Park\VehicleFields;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Поля машины из источника (документ, лот Мигторга, письмо) — одна дверь записи для продажи и хранения (26.10.2026,
 * приборка: писали шесть мест, у каждого своя чистка марки, цвета, VIN и своя защита «VIN у другой машины»).
 *
 * `$chosen` — поле → ['value' => …] (как отдаёт окно «✨», `MigtorgFields`, разбор письма; `create` — источник со своим
 * справочником марок и городов, можно завести новую: Мигторг). По порядку: значение в вид формы (VIN, цвет словарём,
 * марка и модель справочником, госномер ключом, город справочником), что поверх, а что только в пустое — по режиму
 * (`EMPTY` — сама система: Мигторг, письмо, фоновое чтение — только в пустое; `SOURCE` — характеристики в пустое, тождество
 * поверх; `CHOSEN` — человек выбрал значение в «✨» или «Взять» — поверх всё), правила дорожки (форма
 * предложения или `VehicleFields`) — не прошедшее поле пропускается, VIN другой живой машины не пишется
 * (`Identity::carByVin`), запись — дверью дорожки (`UpdateOffer` с `$log`, `UpdateVehicle` с `$log['sources']`).
 */
final class FillCar
{
    public const EMPTY = 'empty';

    public const SOURCE = 'source';

    public const CHOSEN = 'chosen';

    /** Характеристики: источник их знает, но вписанное человеком важнее — поверх только выбором человека. */
    private const SPECS = ['mileage', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power', 'settlement_id', 'body', 'has_keys', 'papers', 'insurer_deadline_at', 'answer_by', 'plate', 'pts', 'sts', 'binding_until'];

    /** @return list<string> поля, которые записаны */
    public function __invoke(Offer|Vehicle $lane, array $chosen, ?User $by, string $mode = self::SOURCE, array $log = []): array
    {
        // Марка источника — один раз: новую заводит только источник со своим справочником марок (`create`: Мигторг).
        $chosenBrand = isset($chosen['brand']) ? (! empty($chosen['brand']['create']) ? Brand::resolve((string) $chosen['brand']['value']) : Brand::known((string) $chosen['brand']['value'])) : null;
        $data = [];
        foreach ($chosen as $field => $item) {
            $value = $item['value'];
            match ($field) {
                'brand' => $chosenBrand ? $data['brand_id'] = $chosenBrand->id : null,
                'model' => null,
                'vin' => ($vin = Vin::full((string) $value)) ? $data['vin'] = $vin : null,
                'color' => $data['color'] = Colors::normalize((string) $value) ?? (string) $value,
                'year' => $data['year'] = (int) $value,
                'mileage', 'engine_volume', 'engine_power' => $data[$field] = (int) $value,
                'transmission', 'drive', 'fuel', 'body', 'papers' => $data[$field] = (string) $value,
                'has_keys' => $data['has_keys'] = (bool) $value,
                // Госномер ключом, как у формы; ПТС и СТС — как в документе.
                'plate' => $data['plate'] = Patterns::plateKey((string) $value),
                'pts', 'sts' => $data[$field] = trim((string) $value),
                'city' => ($id = (Settlement::resolve((string) $value, $item['region'] ?? null) ?? (! empty($item['create']) && trim((string) $value) !== '' ? Settlement::free((string) $value, $item['region'] ?? null) : null))['id'] ?? null) ? $data['settlement_id'] = $id : null,
                'binding_until' => $data['binding_until'] = Carbon::parse((string) $value),
                'deadline' => [$data['insurer_deadline_at'], $data['answer_by']] = [Carbon::parse($value)->toDateString(), Carbon::parse($value)],
                // Своё поле дорожки (у ТС — номер убытка `ref`, страхователь, оценочная): как есть, отберут правила ниже.
                default => $data[$field] = $value,
            };
        }
        $onlyEmpty = $mode === self::EMPTY;
        // Только в пустое — любое поле дорожки, и своё у ТС (оценочная, номер убытка, страхователь), не список.
        $keep = match ($mode) {
            self::EMPTY => array_keys($data),
            self::CHOSEN => [],
            default => self::SPECS,
        };
        foreach ($keep as $field) {
            if (array_key_exists($field, $data) && ! blank($lane->{$field})) {
                unset($data[$field]);
            }
        }
        $brandId = $data['brand_id'] ?? $lane->brand_id;
        // Марки документа нет в справочнике — его модель не заводится под прежней маркой («Kia H5» из документа Hongqi).
        $model = ! (isset($chosen['brand']) && ! $chosenBrand) && isset($chosen['model']['value']);
        if ($onlyEmpty) {
            // Модель источника — только под его же маркой («Solaris» не заводится под Kia) и только в пустое.
            $model = $model && (! $chosenBrand || $chosenBrand->id === $brandId) && (! $lane->model_id || $brandId !== $lane->brand_id);
        }
        // Модель без марки источника — под маркой машины и только знакомая (`CarModel::known`): новую не заводим.
        $brand = $model ? Brand::find($brandId) : null;
        $modelId = $brand ? ($chosenBrand ? CarModel::resolve($brand, (string) $chosen['model']['value']) : CarModel::known($brand, (string) $chosen['model']['value']))?->id : null;
        if ($modelId) {
            $data['model_id'] = $modelId;
        } elseif ($brandId !== $lane->brand_id) {
            $data['model_id'] = null;
        }
        $rules = self::rules($lane);
        $data = array_intersect_key($data, $rules);
        $errors = Validator::make($data, array_intersect_key($rules, $data))->errors();
        $data = array_diff_key($data, array_flip($errors->keys()));
        // VIN другой живой машины — не эта машина (одна машина — одна запись, `Identity`): не пишем.
        if (isset($data['vin']) && ($holder = Identity::carByVin($data['vin'], $lane->car_id))) {
            $lane instanceof Offer && $lane->log(OfferEventType::Note, $by, ['text' => "VIN {$data['vin']} уже у ".Identity::carName($holder).', не записан']);
            unset($data['vin']);
        }
        if ($data) {
            $lane instanceof Offer
                ? app(UpdateOffer::class)($lane, $data, $by, $log)
                : app(UpdateVehicle::class)($lane, $data, $by, $log['sources'] ?? ['документ']);
        }

        return array_keys($data);
    }

    /** Что дорожка принимает из источника и в каком виде. */
    private static function rules(Offer|Vehicle $lane): array
    {
        if ($lane instanceof Vehicle) {
            return VehicleFields::rules(identity: false) + ['model_id' => ['nullable', 'exists:car_models,id']];
        }

        return [
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
            'plate' => ['string', 'max:12'],
            'pts' => ['string', 'max:40'],
            'sts' => ['string', 'max:40'],
            'settlement_id' => ['exists:settlements,id'],
            'insurer_deadline_at' => ['date'],
            'answer_by' => ['date'],
            'binding_until' => ['date'],
        ];
    }
}
