<?php

namespace App\Workflow\Presets;

/**
 * Продажа у Совкомбанка: три отличия от Альфы, и все три — про обязанность.
 *
 * Машину мы обязаны вывезти (маршрут вывоза у него запускается с каждым
 * предложением), значит обязаны и продать. Поэтому «откликов нет» отдаёт её
 * покупателю от страховой, а не в архив; поэтому же по сроку страховой
 * сгорает и черновик — непубликовавшаяся машина тоже должна уйти.
 *
 * Третье — на кого менеджер оформляет покупку. Взял на себя — машину можно
 * отдать до оплаты; для клиента — только после. Это разный порядок одних
 * отрезков, и выражен он двумя ветками с суффиксом `_self`: скрытого
 * признака редактор не покажет, а человек увидел бы одну лестницу там, где
 * их две.
 */
final class Sovcombank extends Route
{
    public function blocks(): array
    {
        return $this->saleBlocks() + [
            'handover' => ['name' => 'Передача машины', 'text' => 'Осталось передать машину покупателю.'],
            // Ветке «на себя» блоки свои: общий на две ветки оборвал бы лестницу
            // на первом же шаге — из него вело бы два продолжения.
            'agreement_self' => ['name' => 'Согласуем с поставщиком', 'text' => 'Мы написали поставщику, что берём машину, и ведём с ним переписку.'],
            'handover_self' => ['name' => 'Забираете машину', 'text' => 'Машину можно забирать: Вы берёте её на себя, счёт будет следом.'],
            'payment_self' => ['name' => 'Оплата после передачи', 'text' => 'Машина у Вас. Поставщик выставил счёт — осталось его оплатить.'],
            'signing_self' => ['name' => 'Подписание документов', 'text' => 'Осталось подписать документы и сдать их поставщику.'],
            'insurer_buyer' => ['name' => 'Машину забирает поставщик', 'text' => 'Срок вышел. Машину забирает покупатель, которого назвал поставщик.'],
        ];
    }

    public function stages(): array
    {
        return $this->head(
            nobody: 'insurer_buyer',
            confirm: [['Беру для клиента', 'manager', 'confirmed'], ['Беру на себя', 'manager', 'confirmed_self'], ['Отказываюсь', 'manager', 'bidding']],
            draftExits: [['Срок страховой вышел', 'timer', 'insurer_buyer']],
            draftDeadline: 'insurer_deadline',
        )
            // Для клиента — деньги вперёд: счёт, документы, передача.
            + ['confirmed' => $this->confirmed('invoice')]
            + $this->invoiceSegment('signing_place')
            + $this->signingSegment('release')
            + $this->releaseSegment('closed_won')
            // На себя — можно постоплатой: машина уезжает, счёт следом.
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
     * ставки не было или она не сыграла. Сделка в базе не заводится —
     * покупатель и сумма записываются на этапе документов.
     */
    private function insurerBuyer(): array
    {
        return [
            'insurer_buyer' => [
                'name' => 'Запросили покупателя у поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'supplier', 'limit_minutes' => 3 * self::DAY,
                // Без второго выхода машина ждала бы покупателя вечно: назвать его обязан поставщик, а обязать его мы не можем.
                'exits' => [['Поставщик назвал покупателя', 'staff', 'insurer_papers'], ['Покупателя не нашли', 'staff', 'no_bids']],
            ],
            'insurer_papers' => [
                // Sold — иначе «Сделка закрыта» недостижима: выдать можно только из сделки.
                'name' => 'Документы с покупателем от поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'us', 'limit_minutes' => 5 * self::DAY, 'offer_state' => 'sold',
                'staff_fields' => [['label' => 'Покупатель'], ['label' => 'Телефон покупателя'], ['label' => 'Сумма сделки']],
                'exits' => [['Документы подписаны', 'staff', 'insurer_release']],
            ],
            'insurer_release' => [
                'name' => 'Передаём машину покупателю поставщика', 'block' => 'insurer_buyer', 'waits_for' => 'us', 'limit_minutes' => 3 * self::DAY,
                'staff_fields' => [['label' => 'Кто забрал машину'], ['label' => 'Дата передачи']],
                'exits' => [['Машина передана', 'staff', 'closed_won']],
            ],
        ];
    }
}
