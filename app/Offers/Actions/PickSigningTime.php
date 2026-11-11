<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\Signing;
use App\Users\User;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Requirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Менеджер (или админ за него) выбрал время подписания: плиткой из свободного времени поставщика или своим — `signing_at`,
 * ответ шага «Время согласовано». Дальше наш ход — письмо записи с `{{ signing }}`.
 */
final class PickSigningTime
{
    public function __construct(private AnswerRequirement $answer, private TakeExit $take) {}

    public function __invoke(Deal $deal, User $by, string $at, bool $staff = false): Deal
    {
        try {
            $time = Carbon::parse($at);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['at' => 'Выберите время']);
        }
        if ($time->isPast()) {
            throw ValidationException::withMessages(['at' => 'Это время уже прошло']);
        }
        $stage = $deal->offer?->position()?->stage;
        $exit = $stage?->loadedExits()->first(fn ($e) => Signing::pickExit($e));
        if (! $deal->isActive() || ! $exit) {
            throw ValidationException::withMessages(['at' => 'Время уже выбрано']);
        }

        return DB::transaction(function () use ($deal, $by, $time, $exit, $staff) {
            $deal->update(['signing_at' => $time]);
            if ($staff) {
                Requirement::closeOpen($deal, $exit->stage_id, ['exit' => $exit->label, 'closed_by' => 'staff', 'by' => $by->id]);
                ($this->take)($deal->offer, $exit, Actor::Manager, $by, ['for' => 'manager']);
            } else {
                $req = $deal->openRequirement()->where('stage_id', $exit->stage_id)->first();
                if (! $req) {
                    throw ValidationException::withMessages(['at' => 'Время уже выбрано']);
                }
                ($this->answer)($req, $exit, $by);
            }

            return $deal->refresh();
        });
    }
}
