<?php

namespace App\Offers\Actions;

use App\Offers\OfferEventType;
use App\Offers\Parallels;
use App\Users\User;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Illuminate\Validation\ValidationException;

/**
 * Ответ менеджера параллельной сделки на её шаге (07.11.2026): машину не двигает — двигает только его теневой шаг
 * (`Parallels`). «Беру в работу» — дальше за главной, «Я передумал» — закрывается только его сделка, главной ничего.
 * Зовёт `AnswerRequirement` (страница сделки и Telegram) после своих проверок, под той же блокировкой.
 */
final class AnswerParallel
{
    public function __construct(private CloseParallels $close) {}

    public function __invoke(Requirement $requirement, Outcome $exit, User $by, array $answer): void
    {
        $deal = $requirement->deal;
        $stage = $requirement->stage;
        if (! Parallels::exits($deal, $stage)->contains(fn (Outcome $e) => $e->id === $exit->id)) {
            throw ValidationException::withMessages(['exit' => 'Эта кнопка не для этой сделки']);
        }
        $requirement->update(['done_at' => now(), 'answer' => ['exit' => $exit->label, 'fields' => $answer]]);
        $offer = $deal->offer;
        $offer->log(OfferEventType::RequirementAnswered, $by, ['exit' => $exit->label, 'fields' => $answer, 'parallel' => $deal->id]);
        if ($exit->isRetreat()) {
            $this->close->one($deal, $by, $exit->label, 'manager');

            return;
        }
        $main = $offer->unsetRelation('positions')->position(Track::Sale)?->stage;
        $to = $main && $exit->to ? Parallels::target($deal, $exit->to->loadMissing('block'), $main) : $exit->to;
        if ($to) {
            Parallels::enter($deal, $to, $by, $exit->label);
        }
    }
}
