<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\SupplierLetterWait;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;

/**
 * Согласие менеджера на новую цену = «Беру в работу» (15.11.2026, владелец: по «Цене за Лот» Т-Страхования, отличной от
 * закупочной, — переоценка, с менеджером согласуем цену; второго вопроса «берём в работу?» нет). Сошлись по сделке
 * (`AnswerReprice::settle`), а сделка маршрута с ожиданием письма поставщика стоит на «Ждём письмо поставщика» или «Согласии
 * менеджера» — сразу на шаг после «Беру в работу» («Письмо о покупке») тем же исходом: `AskBuyer`, «Можно писать поставщику»
 * админам (`ConsentGivenNotice`), просьба согласия закрыта. У Альфы и других маршрутов ничего не меняется.
 */
final class ConsentByReprice
{
    public function __invoke(Deal $deal, ?User $by): bool
    {
        $offer = $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        if (! $stage || ! $deal->isActive() || ! Stage::where('workflow_id', $stage->workflow_id)->where('name', SupplierLetterWait::STAGE)->exists()) {
            return false;
        }
        $consent = SupplierLetterWait::exitOf($stage)?->to ?? ($stage->asksConsent() ? $stage : null);
        $take = $consent?->loadMissing('exits')->exits->first(fn ($e) => $e->takes());
        if (! $take?->to) {
            return false;
        }
        Requirement::where('deal_id', $deal->id)->where('stage_id', $consent->id)->whereNull('done_at')
            ->update(['done_at' => now(), 'answer' => json_encode(['exit' => $take->label, 'fields' => [], 'reprice' => true], JSON_UNESCAPED_UNICODE)]);
        app(EnterStage::class)($offer, $take->to, $by, [], $take);

        return true;
    }
}
