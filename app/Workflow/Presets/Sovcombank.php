<?php

namespace App\Workflow\Presets;

/**
 * Продажа у Совкомбанка: три отличия от Альфы, и все три — про обязанность.
 *
 * Автомобиль мы обязаны вывезти (маршрут вывоза у него запускается с
 * каждым предложением), значит обязаны и продать. Поэтому «подтверждений
 * нет» ведёт к покупателю от страховой, а не в архив. Срок страховой у
 * черновика — только красный срок: «Покупатель от поставщика» выбираем мы
 * сами (06.10.2026, владелец: «не надо автоматически отдавать покупателю от
 * страховой только потому что срок вышел, там не настолько строгие сроки»).
 *
 * Третье — согласовывать покупку с поставщиком нечего (владелец, 05.10.2026:
 * «Совком обязывает нас вывозить все тачки без их подтверждения»): принятое
 * подтверждение сразу ведёт к счёту менеджеру, гаражная сделка «платим мы» —
 * к оплате поставщику.
 */
final class Sovcombank extends Route
{
    public function blocks(): array
    {
        $blocks = self::withGarageBlocks($this->saleBlocks());
        unset($blocks['agreement'], $blocks['declined']);
        $blocks['agreement_garage']['text'] = 'Машина уходит к Вам в гараж: оплачиваем её поставщику и оформляем документы на нас';

        return $blocks + [
            'handover' => ['name' => 'Передача автомобиля', 'text' => 'Осталось передать автомобиль покупателю'],
            'insurer_buyer' => ['name' => 'Покупатель от поставщика', 'text' => 'Срок истёк. Автомобиль передаётся покупателю, которого назвал поставщик'],
        ];
    }

    public function stages(): array
    {
        $stages = $this->head(
            nobody: 'insurer_buyer',
            draftExits: [['Покупатель от поставщика', 'staff', 'insurer_buyer']],
            draftDeadline: 'insurer_deadline',
            accepted: [['invoice', 'buyer'], ['garage_payment', 'garage']],
        )
            // Деньги вперёд: счёт, документы, передача.
            + $this->invoiceSegment('signing_place')
            // Автомобиль у страхователя или у нас: отдаём мы — или забирает сам менеджер сделки (его ветка, 04.10.2026).
            + $this->signingSegment('release', picks: 'buyer_pickup')
            + $this->releaseSegment('closed_won')
            + $this->pickupSegment('closed_won')
            + $this->insurerBuyer()
            + self::garageSegment(confirm: false)
            + $this->tail();
        // Принятие продаёт предложение: вход на первый этап ветки — уже сделка.
        $stages['invoice']['offer_state'] = 'sold';
        $stages['garage_payment']['offer_state'] = 'sold';
        unset($stages['supplier_declined']);

        return $stages;
    }

    /**
     * Срок вышел — покупателя называет страховая. Менеджера здесь нет:
     * подтверждения не было или оно не сыграло. Сделка в базе не заводится —
     * покупатель и сумма записываются на этапе документов.
     */
    private function insurerBuyer(): array
    {
        return [
            'insurer_buyer' => [
                'name' => 'Запрос покупателя у поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'supplier', 'limit_minutes' => 3 * self::DAY,
                // Без второго выхода автомобиль ждал бы покупателя вечно: назвать его обязан поставщик, а обязать его мы не можем.
                'exits' => [['Поставщик назвал покупателя', 'staff', 'insurer_papers'], ['Покупатель не найден', 'staff', 'no_bids']],
            ],
            'insurer_papers' => [
                // Sold — иначе «Сделка закрыта» недостижима: выдать можно только из сделки.
                'name' => 'Оформление сделки с покупателем поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY, 'offer_state' => 'sold',
                'staff_fields' => [['label' => 'Покупатель'], ['label' => 'Телефон покупателя'], ['label' => 'Сумма сделки']],
                'exits' => [['Документы подписаны', 'staff', 'insurer_release']],
            ],
            'insurer_release' => [
                'name' => 'Передача автомобиля покупателю поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Кто получил автомобиль'], ['label' => 'Дата передачи']],
                'exits' => [['Автомобиль передан', 'staff', 'closed_won']],
            ],
        ];
    }
}
