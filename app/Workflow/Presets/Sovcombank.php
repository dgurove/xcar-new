<?php

namespace App\Workflow\Presets;

/**
 * Продажа у Совкомбанка: три отличия от Альфы, и все три — про обязанность.
 *
 * Автомобиль мы обязаны вывезти (маршрут вывоза у него запускается с
 * каждым предложением), значит обязаны и продать. Поэтому «подтверждений
 * нет» отдаёт его покупателю от страховой, а не в архив; поэтому же по сроку
 * страховой сгорает и черновик — непубликовавшийся автомобиль тоже должен
 * уйти.
 *
 * Третье — на кого менеджер оформляет покупку. Взял на себя — автомобиль
 * можно отдать до оплаты; для клиента — только после. Это разный порядок одних
 * отрезков, и выражен он двумя ветками с суффиксом `_self`: скрытого
 * признака редактор не покажет, а человек увидел бы одну лестницу там, где
 * их две.
 */
final class Sovcombank extends Route
{
    public function blocks(): array
    {
        return $this->saleBlocks() + [
            'handover' => ['name' => 'Передача автомобиля', 'text' => 'Осталось передать автомобиль покупателю.'],
            // Ветке «на себя» блоки свои: общий на две ветки оборвал бы лестницу
            // на первом же шаге — из него вело бы два продолжения.
            'agreement_self' => ['name' => 'Согласование с поставщиком', 'text' => 'Уведомили поставщика о покупке и ждём его ответа.'],
            'handover_self' => ['name' => 'Получение автомобиля', 'text' => 'Автомобиль можно забирать: покупка оформляется на Вас, счёт будет выставлен после передачи.'],
            'payment_self' => ['name' => 'Оплата после передачи', 'text' => 'Автомобиль передан Вам. Поставщик выставил счёт — оплатите его.'],
            'signing_self' => ['name' => 'Оформление документов', 'text' => 'Осталось подписать документы и передать их поставщику.'],
            'insurer_buyer' => ['name' => 'Покупатель от поставщика', 'text' => 'Срок истёк. Автомобиль передаётся покупателю, которого назвал поставщик.'],
        ];
    }

    public function stages(): array
    {
        return $this->head(
            nobody: 'insurer_buyer',
            confirm: [['Покупаю для клиента', 'manager', 'confirmed'], ['Покупаю на себя', 'manager', 'confirmed_self'], ['Отказываюсь', 'manager', 'bidding']],
            draftExits: [['Срок страховой истёк', 'timer', 'insurer_buyer']],
            draftDeadline: 'insurer_deadline',
        )
            // Для клиента — деньги вперёд: счёт, документы, передача.
            + ['confirmed' => $this->confirmed('invoice')]
            + $this->invoiceSegment('signing_place')
            + $this->signingSegment('release')
            + $this->releaseSegment('closed_won')
            // На себя — можно постоплатой: автомобиль уезжает, счёт следом.
            + $this->self()
            + $this->insurerBuyer()
            + $this->tail();
    }

    /** Ветка «на себя»: те же отрезки в другом порядке, названия помечены. */
    private function self(): array
    {
        $stages = ['confirmed_self' => $this->confirmed('release_self', '_self')]
            + $this->releaseSegment('invoice_self', '_self')
            + $this->invoiceSegment('signing_place_self', '_self')
            + $this->signingSegment('closed_won', '_self');
        foreach ($stages as &$row) {
            $row['name'] .= ' — на себя';
        }

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
