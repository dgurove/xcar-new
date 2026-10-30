<?php

namespace App\Workflow\Actions;

use App\Offers\OfferEventType;
use App\Support\Ago;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Requirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Продлить срок» одной сделке (19.01.2027, начальник: поставщик просил подождать — красное не должно гореть): «+1 день»,
 * «+3 дня» или «До даты…» в «···» шага пути CRM. Сдвигает срок позиции и открытой просьбы менеджеру, снимает «скоро» и
 * «просрочено» — напомнят и просрочат заново по новому сроку. В «Истории» — строка.
 */
final class ExtendDeadline
{
    public function __invoke(Position $position, Carbon $until, User $by): Position
    {
        if (! $until->isFuture()) {
            throw ValidationException::withMessages(['until' => 'Срок должен быть впереди']);
        }
        DB::transaction(function () use ($position, $until, $by) {
            $position->update(['deadline_at' => $until, 'reminded_at' => null, 'overdue_at' => null]);
            Requirement::where('offer_id', $position->offer_id)->where('stage_id', $position->stage_id)->whereNull('done_at')->update(['due_at' => $until]);
            $position->offer?->log(OfferEventType::Note, $by, ['text' => 'Срок шага «'.$position->stage->name.'» продлён до '.Ago::day($until, true)]);
        });

        return $position;
    }

    /** Новый срок из формы: «+1 день» и «+3 дня» — от нынешнего срока (вышел — от сейчас), «До даты…» — как вписали. */
    public static function untilFrom(Position $position, array $data): Carbon
    {
        if (! empty($data['until'])) {
            return Carbon::parse($data['until'], 'Europe/Moscow');
        }
        $base = $position->deadline_at && $position->deadline_at->isFuture() ? $position->deadline_at : now();

        return $base->copy()->addDays(max(1, (int) ($data['days'] ?? 1)));
    }
}
