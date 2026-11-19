<?php

namespace App\Workflow;

use App\Offers\ExtraAsk;
use App\Offers\Offer;
use App\Offers\RepriceFlow;
use App\Users\User;

/**
 * Что уже даёт текущий шаг продажи в пути сделки CRM (`x-route.path`, «⋯» шага, шаг «Переоценка» и доп. просьба) — чтобы
 * «⋯» окна и «···» страницы (`Offers\CarView::actions`) это не повторяли (19.11.2026, владелец: «не должно быть такого, что две
 * плашки действий», «перепроверь тщательно все этапы»). Действие живёт там, где идёт дело. Условия — те же, что рисуют шаг:
 * переоценка и доп. просьба держат этап (его кнопок тогда нет), исходы в тупик и закрытие — только тому, кто решает
 * (`canManageCrm`), «за менеджера» — тому, кто ведёт сделку (`canEditDeals`).
 */
final class StepOffers
{
    private function __construct(
        /** «Поставщик отказал» — исходом в тупик в «⋯» шага. */
        public readonly bool $declines = false,
        /** «Договор подписан» — кнопкой шага или «За менеджера: «Договор подписан»». */
        public readonly bool $signs = false,
        /** Отмена сделки за менеджера — «За менеджера: «Я передумал»» или «Не берёт» доп. просьбы. */
        public readonly bool $withdraws = false,
    ) {}

    public static function of(Offer $offer, User $user): self
    {
        $deal = $offer->deal?->isActive() ? $offer->deal : null;
        $position = $deal ? $offer->position(Track::Sale) : null;
        $stage = $position?->stage;
        if (! $stage || ! $user->canEditDeals()) {
            return new self;
        }
        // Доп. просьба на стадии решения менеджера — «Берёт» / «Не берёт» за него (`x-route.extra-step`).
        $extra = ExtraAsk::holding($deal);
        if ($extra || ($user->canManageCrm() && RepriceFlow::for($offer))) {
            return new self(withdraws: $extra?->phase() === ExtraAsk::MANAGER && $deal->buyer_id !== null);
        }
        $staff = $stage->exitsFor(Actor::Staff, $deal)->reject(fn (Outcome $x) => $x->acceptsBid());
        $breaks = $user->canManageCrm()
            ? $staff->filter(fn (Outcome $x) => $x->to?->block && $x->to->block_id !== $stage->block_id && $x->to->block->isDeadEnd())
            : collect();
        $moves = $user->canManageCrm() ? $staff->diffKeys($breaks) : $staff->reject(fn (Outcome $x) => \App\Offers\Deal::closes($x));
        $forManager = $deal->buyer_id ? $stage->exitsFor(Actor::Manager, $deal) : collect();
        $signed = fn (Outcome $x) => str_contains(mb_strtolower($x->label), 'подписан');

        return new self(
            declines: $breaks->isNotEmpty(),
            signs: $moves->contains($signed) || $forManager->contains($signed),
            withdraws: $forManager->contains(fn (Outcome $x) => $x->isRetreat()),
        );
    }
}
