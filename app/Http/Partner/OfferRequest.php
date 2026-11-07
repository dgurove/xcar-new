<?php

namespace App\Http\Partner;

use App\Cars\CarFields;
use App\Cars\Identity;
use App\Cars\Vin\Vin;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Offers\Partner\PartnerOffers;
use App\Offers\Pricing;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Правка черновика поставщиком (partner.xcar.ru, 07.11.2026) — белый список: ТС, документы ТС, характеристики, город, адрес
 * осмотра, описание, оценочная и её диапазоны, срок обязывающего (у вендора по комиссии). Вендор, номер убытка, цены продажи,
 * глазик VIN, показ и метки отбрасываются. Закупочную с клиента не берём: её считает сервер по диапазонам — и только когда
 * оценочную вписали (пустое поле оценочную машины не стирает: та же колонка — заявленная стоимость парковки). VIN другой
 * машины не пишется: «уже в работе» без подробностей. Чужое предложение — 404 ещё до проверки полей.
 */
class OfferRequest extends FormRequest
{
    public const BLOCKS = ['car', 'papers', 'specs'];

    private const OWN = ['settlement_id', 'inspection_address', 'description', 'value', 'floor_rules', 'binding_until'];

    public function authorize(): bool
    {
        // Не 403 и не ошибки полей, а 404 — как у любого чужого адреса: по ответу не узнать, есть ли такое предложение.
        abort_unless($this->user()?->isSupplier() && ($offer = $this->route('offer')) instanceof Offer && PartnerOffers::opens($this->user(), $offer), 404);

        return true;
    }

    /** Поля машины — как у модератора: без вендора и номера убытка (их поставщик не меняет), руля и состояния (поля закупки). */
    public static function carFields(): array
    {
        return array_values(array_diff(CarFields::names(Offer::class, ...self::BLOCKS), ['vendor_id', 'claim_ref', 'steering', 'condition']));
    }

    protected function prepareForValidation(): void
    {
        CarFields::clean($this, Offer::class, $this->route('offer')?->engine_volume);
        if ($this->has('value')) {
            $this->merge(['value' => Money::rubles($this->input('value'))]);
        }
    }

    public function rules(): array
    {
        return [
            ...Arr::only(CarFields::rules(Offer::class, self::BLOCKS), self::carFields()),
            'settlement_id' => ['nullable', 'exists:settlements,id'],
            'inspection_address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'floor_rules' => ['nullable', 'string', 'max:2000', fn ($a, $v, $fail) => filled($v) && Pricing::clean($v) === null ? $fail('Диапазоны не сходятся: границы должны расти, последний — «дальше»') : null],
            'binding_until' => ['nullable', 'date'],
            '_fields' => ['nullable', 'array'],
        ];
    }

    public function attributes(): array
    {
        return ['brand_id' => 'марка', 'model_id' => 'модель', 'value' => 'оценочная стоимость', 'vin' => 'VIN', 'year' => 'год'];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $offer = $this->route('offer');
            $data = $v->getData();
            // Цену отдают только у ТС с маркой и моделью: без них мы его не опознаем.
            $brand = array_key_exists('brand_id', $data) ? $data['brand_id'] : $offer?->brand_id;
            $model = array_key_exists('model_id', $data) ? $data['model_id'] : $offer?->model_id;
            if (filled($data['value'] ?? null) && (! $brand || ! $model)) {
                $v->errors()->add($brand ? 'model_id' : 'brand_id', 'Укажите марку и модель');
            }
            // VIN другой машины у нас — ни слияния, ни её номера и названия в ошибке.
            $vin = Vin::full((string) ($data['vin'] ?? ''));
            if ($vin && $vin !== $offer?->vin && Identity::carByVin($vin, $offer?->car_id)) {
                $v->errors()->add('vin', 'ТС с этим VIN уже в работе');
            }
        });
    }

    /** Данные для `UpdateOffer`: тронутое из белого списка; закупочная — по диапазонам с сервера. */
    public function payload(Offer $offer): array
    {
        $only = $this->has('_fields') ? array_values((array) $this->input('_fields')) : null;
        $data = Arr::only($this->validated(), [...self::carFields(), ...self::OWN]);
        if ($only !== null) {
            $data = Arr::only($data, $only);
        }
        if (! Binding::applies($offer)) {
            unset($data['binding_until']);
        }
        // Оценочную не вписали — она не трогается (и закупочной нет); вписали — закупочная по диапазонам, а диапазоны —
        // своими у предложения (снимок того, по чему поставщик посчитал).
        $rules = array_key_exists('floor_rules', $data) ? (Pricing::clean($data['floor_rules']) ?? Pricing::rulesFor($offer)) : Pricing::rulesFor($offer);
        unset($data['floor_rules']);
        if (($data['value'] ?? null) > 0) {
            $data['floor_rules'] = $rules;
            $data['floor_price'] = Pricing::floorFrom((int) $data['value'], $rules);
        } else {
            unset($data['value']);
        }

        return $data;
    }
}
