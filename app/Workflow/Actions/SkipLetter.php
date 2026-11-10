<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Validation\ValidationException;

/**
 * Письмо шага — без письма (10.11.2026, начальник: «чтобы не сразу окно черновика открывало, а спрашивало — писать, закрыть
 * шаг без письма или отмена»): поставщику сообщили сами (телефон, своя почта, мессенджер). Шаг — как после отправленного
 * письма: ход поставщику, срок от сейчас; в шаге «сообщили сами», в «Истории» — строка. Ответ поставщика нажимают как обычно.
 */
final class SkipLetter
{
    public function __invoke(Offer $offer, User $by): Position
    {
        $position = $offer->position(Track::Sale);
        if (! $position || ! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            throw ValidationException::withMessages(['exit' => 'Письмо этого шага уже не ждёт']);
        }
        $payload = array_diff_key($position->payload ?? [], array_flip(['letter_failed', 'letter_bounced', 'letter_id']));
        $position->update([
            'waits_for' => null,
            'deadline_at' => $position->stage->deadlineFor($offer, now()),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => [...$payload, 'letter_at' => now()->toIso8601String(), 'letter_by_hand' => true],
        ]);
        $offer->log(OfferEventType::Note, $by, ['text' => 'Поставщику сообщили не письмом: «'.$position->stage->name.'»']);

        return $position;
    }
}
