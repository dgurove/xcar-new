<?php

namespace App\Offers\Actions;

use App\Cars\OwnerContacts;
use App\Offers\ContactDue;
use App\Offers\Offer;
use App\Users\User;
use App\Support\Phone;

/**
 * Сотрудник поправил контакт владельца, а менеджер, что забирает машину сам, его уже видел (25.11.2026, владелец: «сотрудники когда
 * что-то меняют в контактах — должно уведомление и сообщение менеджеру приходить»): новый показ (`Disclose`) — сообщением в чат
 * сделки «Контакт для получения ТС изменён» и уведомлением чата; в «Получении» у него — новое (`Disclosures::latest`). Контакт ещё
 * не показан — ничего: висит дело «Показать контакты» (`ContactDue`). Правка системой и письмами сюда не идёт (`UpdateOffer`: только
 * человек, ведущий сделки) — менеджеру по-прежнему только то, что выбрал человек (правило 03.11.2026).
 */
final class ResendContact
{
    public const FIELDS = ['insured_name', 'insured_phone', 'insured_phone2', 'insured_contacts', 'inspection_address'];

    public function __construct(private Disclose $disclose) {}

    public function __invoke(Offer $offer, User $by): void
    {
        $deal = $offer->deal()->first();
        if (! $deal || ! $deal->isActive() || ! $deal->buyerPicksUp() || $offer->pickedUp() || ! ContactDue::shownAt($deal)) {
            return;
        }
        $items = [];
        foreach (OwnerContacts::of($offer) as $c) {
            if ($c['name']) {
                $items[] = ['kind' => 'name', 'value' => $c['name']];
            }
            if ($c['phone']) {
                $items[] = ['kind' => 'phone', 'value' => ($n = Phone::normalize($c['phone'])) ? Phone::format($n) : $c['phone']];
            }
        }
        if ($address = trim((string) $offer->inspection_address)) {
            $items[] = ['kind' => 'address', 'value' => $address];
        }
        if ($items) {
            ($this->disclose)($deal, $by, $items, card: true, lead: 'Контакт для получения ТС изменён');
        }
    }
}
