<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\Signing;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Запись подтверждена» (11.11.2026): менеджер называл несколько слотов — поставщик записал на один из них, его мы и
 * вносим (`deals.signing_at`), шаг — на «Подписание», у менеджера карточка встречи с «В календарь».
 */
final class ConfirmSigning
{
    public function __construct(private TakeExit $take) {}

    /** @param  string  $at  «2026-11-12 9:00» (плитка) или «2026-11-12 09:00» (календарь) */
    public function __invoke(Deal $deal, Outcome $exit, User $by, string $at): Deal
    {
        abort_unless(Signing::confirmExit($exit) && $deal->isActive() && $deal->offer?->position()?->stage_id === $exit->stage_id, 404);
        try {
            $at = $at !== '' ? Carbon::parse($at, 'Europe/Moscow') : null;
        } catch (\Throwable) {
            $at = null;
        }
        if (! $at) {
            throw ValidationException::withMessages(['confirm' => 'Выберите, на когда записали']);
        }
        if (! $at || $at->isPast()) {
            throw ValidationException::withMessages(['confirm' => 'Это время уже прошло']);
        }

        return DB::transaction(function () use ($deal, $exit, $by, $at) {
            $deal->update(['signing_at' => $at]);
            ($this->take)($deal->offer, $exit, Actor::Staff, $by);

            return $deal->refresh();
        });
    }
}
