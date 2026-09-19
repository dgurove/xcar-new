<?php

namespace App\Support;

/** Имена полей по-русски для ленты «Изменена: …» — в истории не должно быть имён колонок. */
final class FieldLabels
{
    private const LABELS = [
        'ref' => 'номер убытка', 'claim_ref' => 'номер убытка', 'vin' => 'VIN', 'show_vin' => 'показ VIN', 'plate' => 'госномер', 'year' => 'год', 'color' => 'цвет',
        'brand_id' => 'марка', 'model_id' => 'модель', 'mileage' => 'пробег', 'body' => 'кузов', 'transmission' => 'КПП', 'drive' => 'привод', 'fuel' => 'топливо',
        'engine_volume' => 'объём двигателя', 'engine_power' => 'мощность', 'damage_cause' => 'причина повреждений', 'damage_zones' => 'повреждения', 'damage_note' => 'что заметили',
        'is_runnable' => 'на ходу', 'has_keys' => 'ключи', 'papers' => 'документы', 'incident_date' => 'дата события', 'description' => 'описание',
        'settlement_id' => 'город', 'inspection_address' => 'адрес осмотра', 'floor_price' => 'закупочная цена', 'publish_price' => 'заявленная цена', 'asking_price' => 'цена продажи',
        'min_bid_price' => 'минимальная цена', 'min_bid_share' => 'доля от цены', 'prices_include_vat' => 'НДС', 'tags' => 'метки', 'bids_close_at' => 'срок приёма',
        'chat_enabled' => 'чат', 'share_locked' => 'запрет шеринга', 'recommended' => 'рекомендуем', 'managers_limited' => 'круг менеджеров', 'managers' => 'менеджеры',
        'vendor_id' => 'вендор', 'answer_by' => 'ответ до', 'insured_name' => 'страхователь', 'insured_phone' => 'телефон страхователя', 'flags' => 'признаки', 'holder' => 'держатель',
        'contact_name' => 'контакт', 'contact_phone' => 'телефон', 'contact_email' => 'почта ответственного', 'insurer_deadline_at' => 'срок от вендора',
        'category' => 'категория', 'oversize' => 'негабарит', 'value' => 'оценка', 'contract_kind' => 'основание', 'contract_no' => 'номер договора', 'contract_at' => 'дата договора',
        'assigned_price' => 'назначенная цена', 'pts' => 'ПТС', 'sts' => 'СТС', 'owner_party_id' => 'комитент', 'storage_rate' => 'своя ставка', 'storage_rate_note' => 'почему своя',
        'notes' => 'заметки', 'yard_id' => 'площадка', 'spot' => 'место', 'docs_required' => 'документы вендору', 'state' => 'состояние', 'offer_id' => 'предложение',
    ];

    /** @param  list<string>  $fields */
    public static function list(array $fields): string
    {
        return implode(', ', array_map(fn ($f) => self::LABELS[$f] ?? str_replace('_', ' ', $f), $fields));
    }
}
