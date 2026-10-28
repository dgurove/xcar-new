<?php

namespace App\Offers;

use App\Garage\GaragePayer;
use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Что отдаём и как (`Actions\Give`, 18.10.2026): кому (менеджер; в гараж можно никому — «взяли под себя»), куда — в
 * сделку ценой или в гараж, подтверждение, если принимаем готовое, деньги сделки (схема, сумма собственнику,
 * вознаграждение), кто платит поставщику и наша доля у гаражной, вывоз (кто везёт и где ТС сейчас), согласие продать
 * не выше закупочной. Вывоз `null` — не трогать: так зовут принятие из маршрута и тесты.
 */
final readonly class Giving
{
    /** Где машина, когда отдаём в гараж: у страховой (вывоз в логистике) или уже у менеджера (подготовка сразу). */
    public const WHERE = ['owner' => 'У страховой', 'arrived' => 'Уже у менеджера'];

    /**
     * Кто забирает или везёт: «Не определено» (21.10.2026, владелец: откуда и к кому — знаем, кто повезёт — ещё нет; вывоз
     * заводится тихо, решают потом окном «Кто вывозит»), менеджер сделки сам или логистика (исполнителя выберет админ).
     */
    public const PICKUP = ['later', 'manager', 'us', 'taken'];

    public function __construct(
        public Offer $offer,
        public ?User $manager = null,
        public bool $garage = false,
        public ?int $amount = null,
        public ?Bid $bid = null,
        public ?DealScheme $scheme = null,
        public ?int $ownerPrice = null,
        public ?int $commission = null,
        public CommissionMode $mode = CommissionMode::Payout,
        public ?GaragePayer $payer = null,
        public ?int $share = null,
        public ?string $pickup = null,
        public string $where = 'owner',
        public bool $loss = false,
        // Цена покупателю с гаражом (20.10.2026): машину он берёт по ней счётом ПРАЙМ, закупочной не видит.
        public ?int $buyerPrice = null,
        // Уже вывезли (21.10.2026, владелец: «не хватает указания даты на случай, если уже вывезли»): «Уже у …» в гараж или
        // «Уже забрал» в сделку — когда; вывоз закрывается этой датой (`Logistics\Actions\Backdate`).
        public ?Carbon $arrivedAt = null,
        // Закупочная менеджера в сделке, вписанная админом при принятии (28.10.2026, владелец: «ты определяешь
        // вознаграждение, но ещё можешь поменять закупочную, которая будет у него в сделке»); пусто — с подтверждения.
        public ?int $cost = null,
    ) {}

    /** Принять готовое подтверждение: кому, куда и цена — из него. */
    public static function bid(Bid $bid, mixed ...$rest): self
    {
        return new self($bid->offer, $bid->user, $bid->isGarage(), $bid->amount, $bid, ...$rest);
    }
}
