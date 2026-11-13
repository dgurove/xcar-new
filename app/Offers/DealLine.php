<?php

namespace App\Offers;

use App\Billing\DealDebt;
use App\Users\User;
use App\Workflow\Position;

/**
 * Сделка одной строкой для CRM — этап словом, тон и кто ведёт (27.10.2026, владелец: «в предложениях во вкладке „Все“
 * видно, у кого она в сделке и какой этап»). Одно место для таблицы и строк «Сделок», «Предложений» и «Горит»: раньше
 * каждый шаблон считал слово этапа сам. На шаге оплаты к этапу — срок счёта (`DealDebt`): «Оплата, просрочено 5 д»
 * красным, а не голое «Оплата».
 */
final class DealLine
{
    private function __construct(
        public readonly string $word,
        public readonly string $tone,
        public readonly ?string $who,
        public readonly ?User $person = null, // кто ведёт — аватаром в узком столбце
    ) {}

    public static function of(Deal $deal, ?Position $position = null, ?DealDebt $debt = null): self
    {
        $active = $deal->state === DealState::Active;
        $position ??= $active ? $deal->offer->position() : null;
        [$word, $tone] = match (true) {
            $deal->isAuction() && $deal->state === DealState::Done => ['Продана на аукционе', 'text-accent-text'],
            $deal->declinedBySupplier() => ['Отказ поставщика', 'text-danger'],
            ! $active => [$deal->state->label(), $deal->state === DealState::Done ? 'text-accent-text' : 'text-danger'],
            (bool) $position => self::stage($position, $debt),
            default => [$deal->offer->crmTab(), 'text-ink-muted'],
        };

        // Оформляет на себя (13.11.2026) — у имени: покупатель по договору он сам (договор списки берут сразу, без него — молча).
        $self = $deal->relationLoaded('contract') && $deal->selfBuy() ? ', на себя' : '';

        return new self($word, $tone, $deal->isAuction() ? 'Аукцион' : ($deal->buyer ? $deal->buyer->shortName().$self : null), $deal->isAuction() ? null : $deal->buyer);
    }

    /**
     * Где предложение после продажи: идущая сделка — её этап и менеджер, гараж — этап машины, выданное — закрытой
     * сделкой. До сделки — null (там слово вкладки, `Offer::crmTab`).
     */
    public static function forOffer(Offer $offer): ?self
    {
        if ($deal = $offer->deal) {
            $line = self::of($deal, debt: DealDebt::of($deal));

            return $deal->isGarage() ? new self('В гараж: '.mb_lcfirst($line->word), $line->tone, $line->who, $line->person) : $line;
        }
        if ($offer->state === OfferState::Garage && ($car = $offer->garageCar)) {
            return new self('Гараж: '.mb_strtolower($car->state->label()), 'text-ink-muted', $car->manager?->shortName() ?? 'взяли под себя', $car->manager);
        }
        if ($offer->state === OfferState::Cancelled && ($deal = $offer->declinedDeal)) {
            return new self('Отказ поставщика', 'text-danger', $deal->buyer?->shortName(), $deal->buyer);
        }
        if ($offer->state === OfferState::Delivered && ($deal = $offer->lastDeal)) {
            return new self($deal->isAuction() ? 'Продана на аукционе' : 'Выдан', 'text-accent-text', $deal->isAuction() ? null : $deal->buyer?->shortName(), $deal->isAuction() ? null : $deal->buyer);
        }

        return null;
    }

    /**
     * Этап маршрута, а не блок (10.11.2026, начальник по Volvo 01527: «Согласование с поставщиком», а ждём согласия менеджера):
     * блоки — язык менеджера, в CRM — что сейчас. Пометку гаражной ветки «, в гараж» говорит «В гараж:» спереди. На шаге
     * оплаты с открытым счётом — его срок.
     */
    private static function stage(Position $position, ?DealDebt $debt): array
    {
        $word = str_replace(', в гараж', '', $position->stage->name);
        if ($debt && ! $debt->paid() && $position->stage->isPayStep()) {
            return [$word.', '.$debt->word(), $debt->late() ? 'text-danger' : ''];
        }

        return [$word, ''];
    }
}
