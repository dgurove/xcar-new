<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\OfferEventType;
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
 * Когда покупатель сможет подписать договор — дни и время, можно по нескольку (11.11.2026, Альфа: «напишите желаемую дату и
 * время»; владелец: «сначала дни — можно несколько, потом время — можно несколько»): `deals.signing_wish {days, times}`, в
 * письме записи — «в четверг 12.11 или в пятницу 13.11, в 11:00 или 15:00» (`Signing::wishWords`), какое подтвердили — вносим
 * шторкой «Запись подтверждена» (`ConfirmSigning`). Пока поставщик готовит договор — только сохраняем, «Время подписания»
 * потом пройдёт само (`ApplySigningWish`); на самом выборе — сразу ответ шага (`pass`). Менеджер или сотрудник за него.
 */
final class SaveSigningWish
{
    public function __construct(private AnswerRequirement $answer, private TakeExit $take) {}

    /**
     * @param  list<string>  $days  Y-m-d
     * @param  list<string>  $times  G:i
     */
    public function __invoke(Deal $deal, User $by, array $days, array $times, bool $staff = false): Deal
    {
        abort_unless($deal->isActive() && $deal->signing_at === null, 404);
        $allowed = Signing::times($deal);
        $days = collect($days)->map(function ($d) {
            try {
                return Carbon::createFromFormat('Y-m-d', (string) $d, 'Europe/Moscow')->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        })->filter(fn (?Carbon $d) => $d && $d->copy()->endOfDay()->isFuture())->unique(fn (Carbon $d) => $d->toDateString())->sort()->values();
        $times = array_values(array_intersect($allowed, array_map(fn ($t) => preg_replace('/^0(\d)/', '$1', trim((string) $t)), $times)));
        if ($days->isEmpty()) {
            throw ValidationException::withMessages(['days' => 'Отметьте хотя бы один день']);
        }
        if ($times === []) {
            throw ValidationException::withMessages(['times' => 'Отметьте хотя бы одно время']);
        }

        return DB::transaction(function () use ($deal, $by, $days, $times, $staff) {
            $deal->update([
                'signing_wish' => ['days' => $days->map->toDateString()->all(), 'times' => $times, 'by' => $by->id, 'saved_at' => now()->toIso8601String()],
                'signing_asked_at' => $deal->signing_asked_at ?? now(),
            ]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Покупатель может подписать: '.Signing::wishLine($deal)]);
            // Шаг уже просит время — это и есть ответ.
            if (Signing::picks($deal->offer->unsetRelation('positions')->position()?->stage)) {
                $this->pass($deal, $by, $staff);
            }
            Disclose::publish($deal);

            return $deal->refresh();
        });
    }

    /** «Время согласовано» по названным дням и времени: ответ менеджера, за него — сотрудником или самим (`ApplySigningWish`). */
    public function pass(Deal $deal, ?User $by, bool $staff): void
    {
        $exit = $deal->offer->position()?->stage?->loadedExits()->first(fn ($e) => Signing::pickExit($e));
        if (! $exit) {
            return;
        }
        $req = $deal->openRequirement()->where('stage_id', $exit->stage_id)->first();
        if (! $staff && $req && $by) {
            ($this->answer)($req, $exit, $by);

            return;
        }
        Requirement::closeOpen($deal, $exit->stage_id, ['exit' => $exit->label, 'closed_by' => $by ? 'staff' : 'wish', 'by' => $by?->id]);
        ($this->take)($deal->offer, $exit, Actor::Manager, $by, ['for' => 'manager']);
    }
}
