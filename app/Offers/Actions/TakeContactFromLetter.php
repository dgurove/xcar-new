<?php

namespace App\Offers\Actions;

use App\Mail\Direction;
use App\Mail\Message;
use App\Offers\Offer;

/**
 * Письмо вендора по уже заведённому предложению принесло, с кем и где забирать ТС («Готовы к передаче — +7… Валентин
 * Сергеевич», адрес осмотра) — пустые поля предложения заполняются (04.10.2026: «в письме была инфа, а менеджеру мы
 * ничего не показываем»). Заполненное руками не трогаем; менеджер видит это в «Получении автомобиля» (`Handover`).
 */
final class TakeContactFromLetter
{
    public function __construct(private UpdateOffer $update) {}

    public function __invoke(Offer $offer, Message $message): void
    {
        if ($message->direction !== Direction::In || $message->isOurs()) {
            return;
        }
        $data = [];
        foreach (['insured_name' => 'insured_name', 'insured_phone' => 'insured_phone', 'inspection_address' => 'location'] as $column => $field) {
            $value = trim((string) $message->field($field));
            if ($value !== '' && blank($offer->{$column})) {
                $data[$column] = $value;
            }
        }
        if ($data) {
            ($this->update)($offer, $data, null, ['letter' => $message->id]);
        }
    }
}
