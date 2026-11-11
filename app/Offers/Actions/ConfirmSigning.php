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
 * «Запись подтверждена» (11.11.2026): менеджер называл несколько дней и времён — поставщик записал на одно из них, его мы и
 * вносим (`deals.signing_at`), шаг — на «Подписание», у менеджера карточка встречи с «В календарь».
 */
final class ConfirmSigning
{
    public function __construct(private TakeExit $take) {}

    public function __invoke(Deal $deal, Outcome $exit, User $by, string $day, string $time): Deal
    {
        abort_unless(Signing::confirmExit($exit) && $deal->isActive() && $deal->offer?->position()?->stage_id === $exit->stage_id, 404);
        try {
            $at = Carbon::createFromFormat('Y-m-d G:i', trim($day).' '.trim($time), 'Europe/Moscow');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['confirm' => 'Выберите день и время записи']);
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
