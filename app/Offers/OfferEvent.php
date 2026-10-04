<?php

namespace App\Offers;

use App\Garage\GaragePayer;
use App\Support\FieldLabels;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
            OfferEventType::Created => match (true) {
                isset($p['purchase']) => "Создан из закупки № {$p['purchase']}, ДЛ {$p['dl']}",
                isset($p['park']) => 'Выставлен с парковки',
                default => 'Создан',
            },
            // Правка задачи Мигторга: поля лота («ответ до» — тот же конец торгов, что «срок от вендора») или кадры.
            OfferEventType::Updated => ($p['source'] ?? null) === 'migtorg'
                ? 'С Мигторга: '.(isset($p['photos']) ? $p['photos'].' фото' : FieldLabels::list(array_values(array_diff($p['fields'] ?? [], ['answer_by']))))
                : 'Изменён: '.FieldLabels::list($p['fields'] ?? []),
            // «closed» — состояние, которого больше нет; старые записи ленты остаются читаемыми.
            OfferEventType::StateChanged => OfferState::tryFrom($p['to'] ?? '')?->label() ?? ($p['to'] === 'closed' ? 'Приём закрыт' : (string) $p['to']),
            OfferEventType::BidPlaced => ! empty($p['garage']) ? 'Подтверждение в гараж' : 'Подтверждение '.number_format($p['amount'] ?? 0, 0, '', ' ').' ₽',
            OfferEventType::BidAccepted => isset($p['garage']) ? 'В гараж, поставщику платит '.mb_strtolower(GaragePayer::tryFrom($p['garage'])?->label() ?? '') : 'Подтверждение принято',
            OfferEventType::BidDeclined => 'Подтверждение отклонено',
            OfferEventType::BidWithdrawn => 'Подтверждение отозвано',
            OfferEventType::Interest => ! empty($p['withdrawn']) ? 'Интерес снят' : 'Интерес',
            OfferEventType::StageEntered => (($p['track'] ?? '') === 'service' ? 'Вывоз: ' : 'Этап: ').($p['to'] ?? '').(! empty($p['back']) ? ', шаг отменён' : (! empty($p['exit']) ? ' («'.$p['exit'].'»)' : '')),
            OfferEventType::StageOverdue => 'Срок вышел: '.($p['stage'] ?? ''),
            OfferEventType::StageReminded => 'Срок подходит: '.($p['stage'] ?? ''),
            OfferEventType::RouteDropped => 'Вывоз отменён',
            // Слот: поставили («В слот 4 окт, 16:00»), убрали или часы не смогли выпустить (`error` — чего не хватило).
            OfferEventType::Scheduled => isset($p['error']) ? 'Не вышло в слот: '.$p['error'] : (isset($p['at']) ? 'В слот '.Carbon::parse($p['at'])->translatedFormat('j M, H:i') : 'Убрано из слота'),
            OfferEventType::PlaceChanged => 'Автомобиль: '.(CarPlace::labelOf($p['place'] ?? null) ?? 'место не указано'),
            OfferEventType::Note => (string) ($p['text'] ?? ''),
            OfferEventType::RequirementAnswered => 'Менеджер: «'.($p['exit'] ?? '').'»'.(! empty($p['fields']) ? ' — '.implode(', ', $p['fields']) : ''),
            default => $this->type->value,
        };
    }
}
