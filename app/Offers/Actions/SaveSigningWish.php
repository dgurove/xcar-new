<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Offers\Signing;
use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Когда покупатель сможет подписать договор (11.11.2026, владелец: «спрашивать менеджера о времени, когда Альфа пишет, что
 * договор начали готовить, — чтобы, когда напишут „готов“, сразу им написать про время»): дни и часть дня — `deals.signing_wish`,
 * они встают в письмо «время для подписания» (`{{ signing_days }}`) и первыми в плитки выбора. Менеджер или сотрудник за него.
 */
final class SaveSigningWish
{
    /** @param  list<string>  $days  Y-m-d */
    public function __invoke(Deal $deal, User $by, array $days, string $part = 'any'): Deal
    {
        abort_unless($deal->isActive() && $deal->signing_at === null, 404);
        $days = collect($days)->map(function ($d) {
            try {
                return Carbon::createFromFormat('Y-m-d', (string) $d, 'Europe/Moscow')->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        })->filter(fn (?Carbon $c) => $c && $c->copy()->endOfDay()->isFuture())->unique(fn (Carbon $c) => $c->toDateString())->sort()->values();
        if ($days->isEmpty()) {
            throw ValidationException::withMessages(['days' => 'Отметьте хотя бы один день']);
        }
        $part = array_key_exists($part, Signing::PARTS) ? $part : 'any';
        $deal->update([
            'signing_wish' => ['days' => $days->map->toDateString()->all(), 'part' => $part, 'by' => $by->id, 'at' => now()->toIso8601String()],
            'signing_asked_at' => $deal->signing_asked_at ?? now(),
        ]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Покупатель может подписать: '.Signing::wishLine($deal)]);
        Disclose::publish($deal);

        return $deal;
    }
}
