<?php

namespace App\Offers\Actions;

use App\Offers\ExtraAsk;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Track;

/**
 * «Беру в работу» уже сказано в доп. просьбе — второй раз не спрашиваем (27.11.2026, Корабельников: доп. запрос прямо в
 * подтверждении, `bids.extra_picks`). Просьба встала до этапа согласия, поставщик ответил, менеджер нажал «Беру в работу»
 * (`SettleExtraAsk::decide` — `take`, до согласия это и есть его согласие, `ExtraAsk::labels`). Сделка дошла до «Согласия
 * менеджера» — шаг проходит сам тем же исходом, в «Истории» — «после доп. запроса». Только если согласия на этой сделке ещё не
 * было (`ExtraAsk::consented`): шаг вернули назад — спросим как обычно.
 */
final class ConsentByExtraAsk
{
    public function __construct(private EnterStage $enter) {}

    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! $e->to->asksConsent()) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if (! $deal?->isActive() || ! $deal->buyer_id || $deal->isParallel() || ExtraAsk::holding($deal) || ExtraAsk::consented($deal)) {
            return;
        }
        $took = ExtraAsk::where('deal_id', $deal->id)->where('decision', ExtraAsk::TAKE)->exists();
        $take = $took ? $e->to->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $x) => $x->takes()) : null;
        if (! $take?->to) {
            return;
        }
        Requirement::where('deal_id', $deal->id)->where('stage_id', $e->to->id)->whereNull('done_at')
            ->update(['done_at' => now(), 'answer' => json_encode(['exit' => $take->label, 'fields' => [], 'extra' => true], JSON_UNESCAPED_UNICODE)]);
        ($this->enter)($e->offer->unsetRelation('positions'), $take->to, $deal->buyer, ['label' => $take->label.' после доп. запроса'], $take);
    }
}
