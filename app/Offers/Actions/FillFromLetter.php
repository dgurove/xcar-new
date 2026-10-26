<?php

namespace App\Offers\Actions;

use App\Cars\Settlement;
use App\Cars\Vin\VinAutofill;
use App\Mail\Actions\LinkThread;
use App\Mail\Message;
use App\Mail\Scan\AutoScan;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Vendors\Vendor;

/**
 * Письмо вендора по уже заведённому предложению дописывает его пустые поля (13.10.2026, начальник: «письмо от
 * Т-Страхования пришло, уведомление было, а в сделке не спарсилось» — у Ranger так и не встал VIN из письма). Вписанное
 * руками не трогаем никогда.
 * - Контакт и место осмотра — в любом состоянии (04.10.2026: «в письме была инфа, а менеджеру мы ничего не показываем»;
 *   менеджер видит их в «Получении автомобиля», `Handover`).
 * - У черновика — машина через `ApplyCarFields` (VIN, год, марка, модель, цвет, пробег, город, срок ответа; сторож
 *   `Identity`, зеркало в ТС парковки), характеристики по VIN, закупочная, цена собственника, вендор и адрес для писем.
 *   Есть вложения — черновик читает свои документы сам (`AutoScan`), как заведённый из письма.
 * Письмо вендора — и пересылка сотрудника (`isFromVendor`). Пишет только письмо ровно этой машины: один номер убытка, и
 * он у предложения; без номера — VIN предложения.
 */
final class FillFromLetter
{
    public function __construct(private UpdateOffer $update, private ApplyCarFields $car) {}

    /** `$read` — ставить чтение документов (`AutoScan`); разовая сверка без записи его не ставит. */
    public function __invoke(Offer $offer, Message $message, bool $read = true): void
    {
        if (! $message->isFromVendor() || ! self::about($offer, $message) || LinkThread::otherCar($offer, $message)) {
            return;
        }
        $data = [];
        foreach (['insured_name' => 'insured_name', 'insured_phone' => 'insured_phone', 'inspection_address' => 'location'] as $column => $field) {
            $value = trim((string) $message->field($field));
            if ($value !== '' && blank($offer->{$column})) {
                $data[$column] = $value;
            }
        }
        $log = ['letter' => $message->id];
        if ($offer->state !== OfferState::Draft) {
            $data && ($this->update)($offer, $data, null, $log);

            return;
        }
        $body = $message->text_body ?: strip_tags((string) $message->html_body);
        if (AutoOffer::notAnOffer((string) $message->subject, $body, $message->attachments->pluck('filename')->all())) {
            $data && ($this->update)($offer, $data, null, $log);

            return;
        }
        $chosen = array_intersect_key($message->fields(), array_flip(['vin', 'year', 'brand', 'model', 'color', 'mileage', 'city', 'deadline']));
        if (! isset($chosen['city']) && ($place = Settlement::inAddress((string) $message->field('location'))['name'] ?? null)) {
            $chosen['city'] = ['value' => $place];
        }
        $written = ($this->car)($offer, $chosen, null, onlyEmpty: true, log: $log);
        $offer->refresh();

        $floor = (int) $message->field('floor_price');
        $vendorId = $message->field('vendor_id');
        $data += array_filter([
            'floor_price' => ! $offer->floor_price && $floor >= Pricing::MIN ? $floor : null,
            'owner_price' => ! $offer->owner_price && (int) $message->field('owner_price') > 0 ? (int) $message->field('owner_price') : null,
            'vendor_id' => ! $offer->vendor_id && $vendorId ? (int) $vendorId : null,
            'contact_email' => blank($offer->contact_email) ? $message->field('sender') : null,
            'contact_name' => blank($offer->contact_name) ? $message->field('contact_name') : null,
        ], fn ($v) => $v !== null && $v !== '');
        if (isset($data['vendor_id']) && $offer->prices_include_vat === null) {
            $data['prices_include_vat'] = Vendor::offersVat($data['vendor_id']);
        }
        // Характеристики по VIN — в пустые, как у черновика, заведённого из письма (`PromoteCandidate::data`).
        if ($offer->vin && in_array('vin', $written, true)) {
            foreach (app(VinAutofill::class)->suggest($offer->vin)['values'] as $field => $value) {
                if ($value !== null && blank($offer->{$field}) && ! isset($data[$field]) && $field !== 'brand_id' && $field !== 'model_id') {
                    $data[$field] = $value instanceof \BackedEnum ? $value->value : $value;
                }
            }
        }
        if ($data) {
            ($this->update)($offer, $data, null, $log);
        }
        // Файлы письма едут задачей (`ImportThreadFiles`), документы читаются после них — как у черновика из письма.
        if ($read && $message->attachments->isNotEmpty()) {
            AutoScan::await($offer);
        }
    }

    /** Письмо ровно об этой машине: один номер убытка — номер предложения; без номера — её VIN. */
    private static function about(Offer $offer, Message $message): bool
    {
        $codes = array_values(array_filter($message->keys(), fn ($k) => str_starts_with($k, 'code:')));
        if ($codes) {
            return count($codes) === 1 && $offer->claim_ref_key && $codes[0] === 'code:'.$offer->claim_ref_key;
        }

        return $offer->vin && in_array('vin:'.$offer->vin, $message->keys(), true);
    }
}
