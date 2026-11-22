<?php

namespace App\Offers\Actions;

use App\Notifications\Notify;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimatesClosed;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «Отказаться от оценки» (11.10.2026, владелец): менеджер не берётся оценивать — например, заявленная слишком высокая.
 * Желаемая стоимость обязательна, причина — по желанию. Просьба закрывается отказом (черновик ему больше не виден, его
 * «Оцените» стирается), открытых не осталось — запрет шеринга, поставленный оценкой, снимается (`CloseEstimates`).
 * Админам — тихой строкой в ленте.
 */
final class DeclineEstimate
{
    public function __invoke(Estimate $estimate, int $wish, ?string $reason, User $by): Estimate
    {
        if (! $estimate->isOpen() || $estimate->user_id !== $by->id) {
            throw ValidationException::withMessages(['wish' => 'Оценка уже не нужна']);
        }
        if ($wish < 1) {
            throw ValidationException::withMessages(['wish' => 'Укажите желаемую стоимость']);
        }
        $reason = trim((string) $reason) ?: null;
        $estimate->update(['state' => EstimateState::Declined, 'declined_at' => now(), 'wish_price' => $wish, 'decline_reason' => $reason]);
        $offer = $estimate->offer;
        $offer->log(OfferEventType::Estimate, $by, array_filter(['do' => 'declined', 'user' => $estimate->user_id, 'amount' => $wish, 'reason' => $reason]));
        // Запрет шеринга от оценки и числа — тем же путём, что закрытие просьб (своих открытых тут уже нет).
        app(CloseEstimates::class)($offer, $by, only: []);
        EstimatesClosed::dispatch($offer, [$estimate->id], [$estimate->user_id]);
        app(Notify::class)->estimateDeclined($estimate->loadMissing('offer', 'user'));

        return $estimate;
    }
}
