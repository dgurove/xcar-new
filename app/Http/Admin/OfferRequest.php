<?php

namespace App\Http\Admin;

use App\Cars\CarFields;
use App\Cars\DamageCause;
use App\Cars\DamageZone;
use App\Cars\Papers;
use App\Mail\Extraction\Code;
use App\Offers\AudienceRules;
use App\Offers\DealAudience;
use App\Offers\Flag;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Support\Money;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class OfferRequest extends FormRequest
{
    /**
     * Что правит модератор: ТС, вендор и номер убытка, закупочная и «С НДС» (цены от страховой бывают и так, и так),
     * описание. Цена продажи, заявленная, минимальная,
     * галки, метки, приём и круг показа — админу; лишнее из запроса отбрасывается, а не сохраняется.
     */
    public const MODERATOR = [
        'brand_id', 'model_id', 'year', 'mileage', 'vin', 'show_vin', 'show_address', 'body', 'transmission', 'drive', 'fuel',
        'engine_volume', 'engine_power', 'color', 'settlement_id', 'inspection_address', 'description', 'floor_price', 'value', 'vendor_id', 'claim_ref',
        'prices_include_vat', 'vehicle_category', 'plate', 'pts', 'sts', 'policy_no', 'body_no', 'chassis_no',
    ];

    protected function prepareForValidation(): void
    {
        // Поля машины — одной чисткой с парковкой (`CarFields::clean`): VIN, цвет, коды, пробег, объём литрами.
        CarFields::clean($this, Offer::class, $this->route('offer')?->engine_volume);
        // Целые рубли (Money::rubles): клавиатура с запятой даёт набрать «1 200 000,5» — копейки отбрасываются, а не
        // приклеиваются к рублям.
        $this->merge(collect($this->only(['floor_price', 'value', 'publish_price', 'asking_price', 'min_bid_price']))
            ->map(fn ($v) => Money::rubles($v))
            ->all());
    }

    public function rules(): array
    {
        // Черновик без вендора, номера убытка (ДЛ), марки и модели не сохраняется (владелец 02.10.2026): по номеру письма
        // страховой находят предложение, от вендора — маршрут, круг показа и НДС. Номер — у страховой и лизинговой, у
        // своего транспорта и физлица его нет. Карточка строки шлёт только тронутые поля — там прежнее «можно пусто».
        $full = ! $this->has('_fields') && $this->route('offer')?->state === OfferState::Draft;
        $vendor = $this->filled('vendor_id') ? Vendor::badges()->get((int) $this->input('vendor_id')) : null;
        $need = fn (bool $when = true) => $full && $when ? 'required' : 'nullable';

        // Поля машины — одними правилами с парковкой (`CarFields::rules`), обязательность черновика — здесь.
        return [...CarFields::rules(Offer::class), ...[
            'brand_id' => [$need(), 'exists:brands,id'],
            'model_id' => [$need(), 'exists:car_models,id'],
            'show_vin' => ['boolean'],
            'show_address' => ['boolean'],
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
            'value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
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
            'garage_allowed' => ['boolean'],
            'audience_rules' => ['nullable', 'json'],
            'deal_audience' => ['nullable', 'json'],
            'deal_show_for' => ['nullable', Rule::in(['keep', ...array_map('strval', array_keys(DealAudience::FOR))])],
            'vendor_id' => [$need(), 'exists:vendors,id'],
            'answer_by' => ['nullable', 'date'],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['string', Rule::enum(Flag::class)],
            'holder' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            // «б/н» — только у вендора, у которого так бывает (`claim_ref_optional`, ТИТ 17.10.2026).
            'claim_ref' => [$need(in_array($vendor?->kind, [Kind::Insurer, Kind::Leasing], true)), 'string', 'max:60', function ($attr, $value, $fail) {
                $owner = Vendor::find($this->input('vendor_id', $this->route('offer')?->vendor_id));
                if (Code::isNone($value) && ! $owner?->claim_ref_optional) {
                    $fail('У '.($owner?->name ?? 'вендора').' номер обязателен');
                }
            }],
            'insurer_deadline_at' => ['nullable', 'date'],
        ]];
    }

    public function attributes(): array
    {
        return ['brand_id' => 'марка', 'model_id' => 'модель', 'plate' => 'госномер', 'pts' => 'ПТС', 'sts' => 'СТС', 'body_no' => 'номер кузова', 'chassis_no' => 'номер шасси', 'vendor_id' => 'вендор', 'claim_ref' => 'номер', 'asking_price' => 'цена продажи', 'floor_price' => 'закупочная цена', 'value' => 'оценочная стоимость', 'publish_price' => 'заявленная цена'];
    }

    /**
     * Данные для UpdateOffer. Полный редактор присылает всю форму: не пришла галка — снята, не пришёл список — пуст.
     * Карточка строки присылает только тронутые поля и их имена в `_fields[]` (save_bar_controller): остальное не трогаем,
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
        // Галки и «кому» блока «Показ» — только когда он был в форме (`_show`): в сделке и в гараже редактор его не
        // рисует (06.10.2026), и сохранение полей ТС не должно их сбрасывать.
        $show = $only !== null || $this->has('_show');
        $showOnly = ['chat_enabled', 'share_locked', 'recommended', 'garage_allowed'];
        foreach (['show_vin', 'show_address', 'chat_enabled', 'share_locked', 'recommended', 'prices_include_vat', 'garage_allowed'] as $flag) {
            if ($sent($flag) && ($show || ! in_array($flag, $showOnly, true))) {
                $data[$flag] = $this->boolean($flag);
            }
        }
        if ($sent('audience_rules') && $show) {
            // Волны показа приходят JSON-строкой из шторки «Кому»; пустые — «как у вендора» (null).
            $data['audience_rules'] = AudienceRules::normalize($this->input('audience_rules')) ?: null;
        }
        // Белый список «Кому показывать в сделке» — только когда его блок был в форме (`_deal_show`): в сделке
        // редактор его рисует, остальные сохранения список не трогают. Всё снято — пусто («Никому»).
        $dealShow = $only !== null || $this->has('_deal_show');
        unset($data['deal_audience'], $data['deal_show_for']);
        if ($dealShow && $this->user()->canManageCrm()) {
            // Список — JSON из шторки модуля «Показ» (`[{type, id}]`), чужие id отбросит `DealAudience::rows`.
            if ($sent('deal_audience') && $this->has('deal_audience')) {
                $list = DealAudience::fromJson($this->input('deal_audience'));
                $data['deal_audience_users'] = $list['users'];
                $data['deal_audience_groups'] = $list['groups'];
            }
            // «Сколько показывать»: `keep` — срок не трогаем, пусто — пока идёт сделка, иначе минуты от сейчас.
            $for = $this->input('deal_show_for');
            if ($sent('deal_show_for') && $this->has('deal_show_for') && $for !== 'keep') {
                $data['deal_show_until'] = filled($for) ? now()->addMinutes((int) $for) : null;
            }
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
