<?php

namespace App\Http\Admin;

use App\Cars\Body;
use App\Cars\DamageCause;
use App\Cars\DamageZone;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Transmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Числа приходят с пробелами и знаком рубля — чистим до цифр.
        $this->merge(collect($this->only(['mileage', 'floor_price', 'publish_price', 'asking_price', 'min_bid_price', 'engine_volume', 'engine_power']))
            ->map(fn ($v) => $v === null || $v === '' ? null : (int) preg_replace('/\D+/', '', (string) $v))
            ->all());
    }

    public function rules(): array
    {
        return [
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model_id' => ['nullable', 'exists:car_models,id'],
            'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'mileage' => ['nullable', 'integer', 'max:5000000'],
            'vin' => ['nullable', 'string', 'max:17'],
            'show_vin' => ['boolean'],
            'body' => ['nullable', Rule::enum(Body::class)],
            'transmission' => ['nullable', Rule::enum(Transmission::class)],
            'drive' => ['nullable', Rule::enum(Drive::class)],
            'fuel' => ['nullable', Rule::enum(Fuel::class)],
            'engine_volume' => ['nullable', 'integer', 'max:20000'],
            'engine_power' => ['nullable', 'integer', 'max:3000'],
            'color' => ['nullable', 'string', 'max:32'],
            'damage_cause' => ['nullable', Rule::enum(DamageCause::class)],
            'damage_zones' => ['nullable', 'array'],
            'damage_zones.*' => [Rule::enum(DamageZone::class)],
            'is_runnable' => ['nullable', 'boolean'],
            'has_keys' => ['nullable', 'boolean'],
            'papers' => ['nullable', Rule::enum(Papers::class)],
            'incident_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'settlement_id' => ['nullable', 'exists:settlements,id'],
            'inspection_address' => ['nullable', 'string', 'max:255'],
            'floor_price' => ['nullable', 'integer', 'max:100000000'],
            'publish_price' => ['nullable', 'integer', 'max:100000000', ...($this->filled('asking_price') ? ['lte:asking_price'] : [])],
            'asking_price' => ['nullable', 'integer', 'max:100000000'],
            'min_bid_price' => ['nullable', 'integer', 'max:100000000'],
            'min_bid_share' => ['nullable', 'numeric', 'between:0,1'],
            'prices_include_vat' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'bids_close_at' => ['nullable', 'date'],
            'chat_enabled' => ['boolean'],
            'share_locked' => ['boolean'],
            'insurer_id' => ['nullable', 'exists:insurers,id'],
            'claim_ref' => ['nullable', 'string', 'max:60'],
            'insurer_deadline_at' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return ['brand_id' => 'марка', 'model_id' => 'модель', 'asking_price' => 'цена продажи', 'floor_price' => 'закупочная цена', 'publish_price' => 'заявленная цена'];
    }

    public function payload(): array
    {
        $data = $this->validated();
        $data['show_vin'] = $this->boolean('show_vin');
        $data['chat_enabled'] = $this->boolean('chat_enabled');
        $data['share_locked'] = $this->boolean('share_locked');
        $data['damage_zones'] = $data['damage_zones'] ?? [];
        $data['tags'] = array_values(array_filter($data['tags'] ?? []));
        $data['prices_include_vat'] = $this->boolean('prices_include_vat');
        foreach (['is_runnable', 'has_keys'] as $tri) {
            $data[$tri] = $this->filled($tri) ? $this->boolean($tri) : null;
        }

        return $data;
    }
}
