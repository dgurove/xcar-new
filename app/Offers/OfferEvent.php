<?php

namespace App\Offers;

use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Support\FieldLabels;
use App\Support\Money;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Лот Мигторга, откуда запись (`payload.lot`); у записей до 05.10.2026 номера не было — лот, взятый предложением.
     * Ссылка на объявление стоит в строке истории.
     */
    public function migtorgLot(): ?int
    {
        if (($this->payload['source'] ?? null) !== 'migtorg') {
            return null;
        }

        return ($this->payload['lot'] ?? null) ?: once(fn () => DB::table('migtorg_lots')->where('offer_id', $this->offer_id)->orderByDesc('id')->value('id'));
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
                ? 'С Мигторга: '.match (true) {
                    isset($p['photos']) => $p['photos'].' фото',
                    isset($p['fields']) => FieldLabels::list(array_values(array_diff($p['fields'], ['answer_by']))),
                    default => 'подтвердили',
                }
            : (($p['source'] ?? null) === 'valuation'
                ? 'Оценка из текста: '.implode(', ', array_filter([
                    isset($p['value']) ? 'оценочная '.Money::rub((int) $p['value']) : null,
                    isset($p['floor']) ? 'закупочная '.Money::rub((int) $p['floor']) : null,
                ]))
                : 'Изменён: '.FieldLabels::list($p['fields'] ?? [])),
            // «closed» — состояние, которого больше нет; старые записи ленты остаются читаемыми.
            OfferEventType::StateChanged => OfferState::tryFrom($p['to'] ?? '')?->label() ?? ($p['to'] === 'closed' ? 'Приём закрыт' : (string) $p['to']),
            OfferEventType::BidPlaced => ! empty($p['garage']) ? 'Подтверждение в гараж' : 'Подтверждение '.number_format($p['amount'] ?? 0, 0, '', ' ').' ₽',
            OfferEventType::BidAccepted => isset($p['garage']) ? 'В гараж, поставщику платит '.mb_strtolower(GaragePayer::tryFrom($p['garage'])?->label() ?? '') : 'Подтверждение принято',
            OfferEventType::BidDeclined => 'Подтверждение отклонено',
            OfferEventType::BidWithdrawn => 'Подтверждение отозвано',
            OfferEventType::Interest => ! empty($p['withdrawn']) ? 'Интерес снят' : 'Интерес',
            OfferEventType::StageEntered => (($p['track'] ?? '') === 'service' ? 'Вывоз: ' : 'Этап: ').($p['to'] ?? '').(! empty($p['back']) ? ', шаг отменён' : (! empty($p['exit']) ? ' («'.$p['exit'].'»'.(! empty($p['letter']) ? ' по письму' : '').')' : '')),
            OfferEventType::StageOverdue => 'Срок вышел: '.($p['stage'] ?? ''),
            OfferEventType::StageReminded => 'Срок подходит: '.($p['stage'] ?? ''),
            OfferEventType::RouteDropped => 'Вывоз отменён',
            OfferEventType::InsurerReplied => 'Страховая ответила'.(! empty($p['contact']) ? ', есть контакт' : ''),
            OfferEventType::PickupAssigned => 'Вывоз: '.($p['who'] ?? 'мы').', '.mb_strtolower(Destination::tryFrom($p['to'] ?? '')?->label() ?? ''),
            // Слот: поставили («В слот 4 окт, 16:00»), убрали или часы не смогли выпустить (`error` — чего не хватило).
            OfferEventType::Scheduled => isset($p['error']) ? 'Не вышло в слот: '.$p['error'] : (isset($p['at']) ? 'В слот '.Carbon::parse($p['at'])->translatedFormat('j M, H:i') : 'Убрано из слота'),
            OfferEventType::PlaceChanged => 'Автомобиль: '.(CarPlace::labelOf($p['place'] ?? null) ?? 'место не указано'),
            OfferEventType::Note => (string) ($p['text'] ?? ''),
            OfferEventType::Garage => self::garageText($p),
            OfferEventType::RequirementAnswered => 'Менеджер: «'.($p['exit'] ?? '').'»'.(! empty($p['fields']) ? ' — '.implode(', ', $p['fields']) : ''),
            default => $this->type->value,
        };
    }

    /**
     * Строка истории гаража (06.10.2026): этапы подготовки и продажи, расходы, счёт и выплата — то, что раньше жило
     * только в пути машины, теперь и в «Истории» предложения.
     */
    private static function garageText(array $p): string
    {
        $sum = fn (string $key) => isset($p[$key]) ? Money::exact((float) $p[$key]) : '';

        return match ($p['do'] ?? null) {
            'stage' => match ($state = CarState::tryFrom($p['state'] ?? '')) {
                CarState::Sold => 'Продана'.(isset($p['price']) ? ' за '.$sum('price') : ''),
                null => 'Гараж',
                default => ($state->isPrep() ? 'Гараж: ' : 'Продажа: ').mb_strtolower($state->label()),
            },
            'cost' => 'Расход: '.mb_strtolower($p['title'] ?? '').' '.$sum('amount').(($p['payer'] ?? null) === 'xcar' ? ', платили мы' : ''),
            'cost_edit' => 'Расход изменён: '.mb_strtolower($p['title'] ?? '').' '.$sum('amount').(($p['payer'] ?? null) === 'xcar' ? ', платили мы' : ''),
            'cost_removed' => 'Расход убран: '.mb_strtolower($p['title'] ?? '').' '.$sum('amount'),
            'invoice' => (($p['to'] ?? null) === 'buyer' ? 'Счёт покупателю ' : 'Счёт менеджеру ').$sum('amount'),
            'payout' => 'Выплата менеджеру '.$sum('amount'),
            'void' => 'Аннулирован документ '.$sum('amount'),
            'unsold' => 'Не продана',
            default => 'Гараж',
        };
    }
}
