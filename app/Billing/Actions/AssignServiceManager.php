<?php

namespace App\Billing\Actions;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\OfferEventType;
use App\Offers\Offer;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Чья ручная ссылка (22.11.2026, владелец: «в „Без менеджера“ — менеджеров указывать и к сделке существующей привязывать»): у
 * разовой оплаты без сделки — менеджер (`manager_id`), с ТС системы — ещё и машина: оплата засчитывается в её сделку
 * (`AttachServiceCar`), менеджер — её покупатель, и строка уходит в сделку. Пусто — снова «Без менеджера».
 */
final class AssignServiceManager
{
    public function __construct(private AttachServiceCar $attach) {}

    public function __invoke(Invoice $invoice, ?User $manager, ?Offer $offer, User $by): Invoice
    {
        if (! $invoice->isService() || $invoice->state === InvoiceState::Void) {
            throw ValidationException::withMessages(['manager_id' => 'Менеджера назначают только разовой оплате']);
        }
        if ($manager && ! $manager->hasRole(Role::Manager)) {
            throw ValidationException::withMessages(['manager_id' => 'Это не менеджер']);
        }
        // Машину привязывают заново, только если её поменяли: иначе каждое «Сохранить» писало бы в «Историю» и пересчитывало зачёт.
        if ($offer && $offer->id !== $invoice->offer_id) {
            $invoice = ($this->attach)($invoice, $offer, null, $by);
        }
        $manager = $invoice->creditDeal?->buyer ?? $manager;
        if ($invoice->manager_id !== $manager?->id) {
            $invoice->update(['manager_id' => $manager?->id]);
            $invoice->carOffer()?->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::rub($invoice->total).($manager ? ': менеджер '.$manager->shortName() : ': без менеджера')]);
        }

        return $invoice->fresh();
    }
}
