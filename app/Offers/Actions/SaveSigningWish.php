<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Offers\Signing;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Когда покупатель сможет подписать договор — день и время (11.11.2026, Альфа: «напишите желаемую дату и время»; владелец:
 * «заранее спрашивать у менеджера не только дату, но и время», «кнопками в сообщении»). Пока поставщик готовит договор —
 * `deals.signing_wish`, «Время подписания» потом пройдёт само (`ApplySigningWish`); на самом выборе — сразу ответ шага
 * (`PickSigningTime`). Менеджер или сотрудник за него (`$staff`).
 */
final class SaveSigningWish
{
    public function __construct(private PickSigningTime $pick) {}

    public function __invoke(Deal $deal, User $by, string $day, string $time, bool $staff = false): Deal
    {
        abort_unless($deal->isActive() && $deal->signing_at === null, 404);
        try {
            $at = Carbon::createFromFormat('Y-m-d G:i', trim($day).' '.trim($time), 'Europe/Moscow');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['at' => 'Выберите день и время']);
        }
        if (! $at || $at->isPast()) {
            throw ValidationException::withMessages(['at' => 'Это время уже прошло']);
        }
        if ($deal->offer?->vendor?->signing_office && ($why = Signing::outsideHours($deal, $at))) {
            throw ValidationException::withMessages(['at' => $why]);
        }
        // Шаг уже просит время — это и есть ответ.
        if (Signing::picks($deal->offer?->position()?->stage)) {
            return ($this->pick)($deal, $by, $at->format('Y-m-d H:i'), staff: $staff);
        }
        $deal->update([
            'signing_wish' => ['at' => $at->format('Y-m-d H:i'), 'by' => $by->id, 'saved_at' => now()->toIso8601String()],
            'signing_asked_at' => $deal->signing_asked_at ?? now(),
        ]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Покупатель может подписать: '.Signing::short($at)]);
        Disclose::publish($deal);

        return $deal;
    }
}
