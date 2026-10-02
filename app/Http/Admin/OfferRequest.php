<?php

namespace App\Http\Admin;

use App\Cars\Body;
use App\Cars\Colors;
use App\Cars\DamageCause;
use App\Cars\DamageZone;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Papers;
use App\Cars\Transmission;
use App\Offers\AudienceRules;
use App\Offers\Flag;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Support\Liters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class OfferRequest extends FormRequest
{
    /**
     * Что правит модератор: ТС, вендор и номер убытка, закупочная, описание. Цена продажи, заявленная, минимальная,
     * галки, метки, приём и круг показа — админу; лишнее из запроса отбрасывается, а не сохраняется.
     */
    public const MODERATOR = [
        'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'show_address', 'body', 'transmission', 'drive', 'fuel',
        'engine_volume', 'engine_power', 'color', 'settlement_id', 'inspection_address', 'description', 'floor_price', 'vendor_id', 'claim_ref',
    ];

    protected function prepareForValidation(): void
    {
        // Числа приходят с пробелами и знаком рубля — чистим до цифр.
        if ($this->has('engine_volume')) {
            $this->merge(['engine_volume' => Liters::parse($this->input('engine_volume'), $this->route('offer')?->engine_volume)]);
        }
        // Цвет — как в форме ТС парковки: словарь пишет его одинаково («черный» → «Черный»), опечаток не правит.
        if ($this->filled('color')) {
            $this->merge(['color' => Colors::normalize($this->input('color'), fuzzy: false) ?? trim((string) $this->input('color'))]);
        }
        $this->merge(collect($this->only(['mileage', 'floor_price', 'publish_price', 'asking_price', 'min_bid_price', 'engine_power']))
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
            'show_address' => ['boolean'],
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
            'tag_colors' => ['nullable', 'json'],
            'bids_close_at' => ['nullable', 'date'],
            'chat_enabled' => ['boolean'],
            'share_locked' => ['boolean'],
            'recommended' => ['boolean'],
            'audience_id' => ['nullable', 'exists:audiences,id'],
            'audience_rules' => ['nullable', 'json'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'answer_by' => ['nullable', 'date'],
            'insured_name' => ['nullable', 'string', 'max:80'],
            'insured_phone' => ['nullable', 'string', 'max:20'],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['string', Rule::enum(Flag::class)],
            'holder' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'claim_ref' => ['nullable', 'string', 'max:60'],
            'insurer_deadline_at' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return ['brand_id' => 'марка', 'model_id' => 'модель', 'asking_price' => 'цена продажи', 'floor_price' => 'закупочная цена', 'publish_price' => 'заявленная цена'];
    }

    /**
     * Данные для UpdateOffer. Полный редактор присылает всю форму: не пришла галка — снята, не пришёл список — пуст.
     * Окошко строки присылает только тронутые поля и их имена в `_fields[]` (autosave): остальное не трогаем,
     * иначе каждое сохранение перетирало бы чужие правки и сбрасывало галки, которых в запросе нет.
     */
    public function payload(): array
    {
        $only = $this->has('_fields') ? array_values((array) $this->input('_fields')) : null;
        $sent = fn (string $key) => $only === null || in_array($key, $only, true);
        $data = $this->validated();
        if ($only !== null) {
            $data = Arr::only($data, $only);
        }
        foreach (['show_vin', 'show_address', 'chat_enabled', 'share_locked', 'recommended', 'prices_include_vat'] as $flag) {
            if ($sent($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }
        if ($sent('audience_rules')) {
            // Волны показа приходят JSON-строкой из шторки «Кому»; пустые — «как у вендора» (null).
            $data['audience_rules'] = AudienceRules::normalize($this->input('audience_rules')) ?: null;
        }
        // Повреждений, «на ходу» и ключей в формах больше нет (блок «Состояние» убран, 30.09.2026): чего нет в запросе — не трогаем.
        if ($sent('damage_zones') && $this->has('damage_zones')) {
            $data['damage_zones'] = $this->validated('damage_zones') ?? [];
        }
        if ($sent('tags')) {
            $data['tags'] = array_values(array_filter($this->validated('tags') ?? []));
        }
        if ($sent('tag_colors')) {
            // Цвет своей метки: только известные цвета и только строки-названия.
            $colors = json_decode((string) $this->input('tag_colors'), true);
            $data['tag_colors'] = collect(is_array($colors) ? $colors : [])
                ->filter(fn ($c, $n) => is_string($n) && mb_strlen($n) <= 40 && array_key_exists($c, Tag::COLORS))->all() ?: null;
        }
        // Признаки ставит разбор писем, в редакторе их нет: отсутствие в форме — не «снять все».
        if ($this->has('flags')) {
            $data['flags'] = array_values(array_filter($data['flags']));
        }
        foreach (['is_runnable', 'has_keys'] as $tri) {
            if ($sent($tri) && $this->has($tri)) {
                $data[$tri] = $this->filled($tri) ? $this->boolean($tri) : null;
            }
        }

        if ($this->user()->canManageCrm()) {
            return $data;
        }
        // Вендор у опубликованного ведёт маршрут и волны показа — модератор меняет его только в черновике.
        $draft = $this->route('offer')?->state === OfferState::Draft;

        return Arr::only($data, $draft ? self::MODERATOR : array_diff(self::MODERATOR, ['vendor_id']));
    }
}
