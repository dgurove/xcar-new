<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'user_id', 'type', 'payload'])]
class OfferEvent extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => OfferEventType::class, 'payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Строка истории на карточке. */
    public function text(): string
    {
        $p = $this->payload ?? [];

        return match ($this->type) {
            OfferEventType::Created => 'Создан',
            OfferEventType::Updated => 'Изменён: '.implode(', ', $p['fields'] ?? []),
            OfferEventType::StateChanged => OfferState::from($p['to'])->label(),
            OfferEventType::BidPlaced => 'Подтверждение '.number_format($p['amount'] ?? 0, 0, '', ' ').' ₽',
            OfferEventType::BidAccepted => 'Подтверждение принято',
            OfferEventType::BidDeclined => 'Подтверждение отклонено',
            OfferEventType::BidWithdrawn => 'Подтверждение отозвано',
            OfferEventType::Interest => 'Интерес',
            OfferEventType::StageEntered => (($p['track'] ?? '') === 'service' ? 'Вывоз: ' : 'Этап: ').($p['to'] ?? '').(! empty($p['exit']) ? ' («'.$p['exit'].'»)' : ''),
            OfferEventType::StageOverdue => 'Срок вышел: '.($p['stage'] ?? ''),
            OfferEventType::StageReminded => 'Срок подходит: '.($p['stage'] ?? ''),
            OfferEventType::RouteDropped => 'Вывоз отменён',
            OfferEventType::PlaceChanged => 'Автомобиль: '.CarPlace::labelOf($p['place'] ?? null),
            OfferEventType::RequirementAnswered => 'Менеджер: «'.($p['exit'] ?? '').'»'.(! empty($p['fields']) ? ' — '.implode(', ', $p['fields']) : ''),
            default => $this->type->value,
        };
    }
}
