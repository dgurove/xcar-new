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
 * Когда покупатель сможет подписать договор — слоты «день + час», сколько угодно (11.11.2026, Альфа: «напишите желаемую дату и
 * время»; владелец: «в одни дни может в 12 и 14, в другие только в 9 и 16»): `deals.signing_wish.slots`, в письме записи —
 * «в четверг 12.11 в 12:00 или 14:00, в пятницу 13.11 в 9:00» (`Signing::wishWords`), какое подтвердили — вносим
 * шторкой «Запись подтверждена» (`ConfirmSigning`). Пока поставщик готовит договор — только сохраняем, «Время подписания»
 * потом пройдёт само (`ApplySigningWish`); на самом выборе — сразу ответ шага (`pass`). Менеджер или сотрудник за него.
 */
final class SaveSigningWish
{
    public function __construct(private AnswerRequirement $answer, private TakeExit $take) {}

    /** @param  list<string>  $slots  «2026-11-12 9:00» — только из сетки (`Signing::grid`) */
    public function __invoke(Deal $deal, User $by, array $slots, bool $staff = false): Deal
    {
        abort_unless($deal->isActive() && $deal->signing_at === null, 404);
        $grid = Signing::grid($deal)->flatten()->map(fn (Carbon $c) => Signing::slotKey($c))->all();
        $slots = array_values(array_intersect($grid, array_map(fn ($s) => trim((string) $s), $slots)));
        if ($slots === []) {
            throw ValidationException::withMessages(['slots' => 'Отметьте хотя бы одно время']);
        }

        return DB::transaction(function () use ($deal, $by, $slots, $staff) {
            $deal->update([
                'signing_wish' => ['slots' => $slots, 'by' => $by->id, 'saved_at' => now()->toIso8601String()],
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
