<?php

namespace App\Offers;

use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Support\Changes;
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

    /**
     * Запись только для админа (15.10.2026): подтверждения и их цены (модератору подтверждения не видны), сделка, гараж,
     * оценки, ответы и письма поставщику, правки цен продажи и денег сделки — модератор их в «Истории» не видит.
     */
    public function adminOnly(): bool
    {
        return in_array($this->type, [OfferEventType::BidPlaced, OfferEventType::BidWithdrawn, OfferEventType::BidAccepted, OfferEventType::BidDeclined,
            OfferEventType::Garage, OfferEventType::Estimate, OfferEventType::InsurerReplied, OfferEventType::SupplierLetter], true)
            || ($this->type === OfferEventType::Updated && array_intersect(array_keys($this->payload['changes'] ?? []) ?: ($this->payload['fields'] ?? []),
                ['asking_price', 'min_bid_price', 'cost', 'supplier_cost', 'deal_owner_price', 'amount', 'commission', 'share']));
    }

    /** Строка истории на карточке. */
    public function text(): string
    {
        $p = $this->payload ?? [];

        return match ($this->type) {
            OfferEventType::Created => match (true) {
                isset($p['purchase']) => "Создан из закупки № {$p['purchase']}, ДЛ {$p['dl']}",
                isset($p['park']) => 'Выставлен с парковки',
                isset($p['mail']) => 'Заведён из письма',
                default => 'Создан',
            },
            OfferEventType::Updated => self::updatedText($p),
            // «closed» — состояние, которого больше нет; старые записи ленты остаются читаемыми.
            OfferEventType::StateChanged => OfferState::tryFrom($p['to'] ?? '')?->label() ?? ($p['to'] === 'closed' ? 'Приём закрыт' : (string) $p['to']),
            OfferEventType::BidPlaced => ! empty($p['garage']) ? 'Подтверждение в гараж' : 'Подтверждение '.Money::plain($p['amount'] ?? 0),
            OfferEventType::BidAccepted => isset($p['garage']) ? 'В гараж, поставщику платит '.mb_strtolower(GaragePayer::tryFrom($p['garage'])?->label() ?? '') : 'Подтверждение принято',
            OfferEventType::BidDeclined => 'Подтверждение отклонено',
            OfferEventType::BidWithdrawn => 'Подтверждение отозвано',
            OfferEventType::Interest => ! empty($p['withdrawn']) ? 'Интерес снят' : (! empty($p['placed_by']) ? 'Интерес, внесён за менеджера' : 'Интерес'),
            OfferEventType::StageEntered => (($p['track'] ?? '') === 'service' ? 'Вывоз: ' : 'Этап: ').($p['to'] ?? '').(! empty($p['back']) ? ', шаг отменён' : (! empty($p['exit']) ? ' («'.$p['exit'].'»'.(! empty($p['letter']) ? ' по письму' : '').')' : '')),
            OfferEventType::StageOverdue => 'Срок вышел: '.($p['stage'] ?? ''),
            OfferEventType::StageReminded => 'Срок подходит: '.($p['stage'] ?? ''),
            OfferEventType::RouteDropped => 'Вывоз отменён',
            OfferEventType::InsurerReplied => 'Страховая ответила'.(! empty($p['contact']) ? ', есть контакт' : ''),
            OfferEventType::SupplierLetter => 'Письмо поставщику отправлено',
            OfferEventType::Estimate => self::estimateText($p),
            // «На аукцион» (13.10.2026): отправили, запланировали по истечении срока, сняли план, вернули в продажу или в черновики.
            OfferEventType::Auction => match (true) {
                ! empty($p['planned']) => 'На аукцион по истечении срока, если не будет подтверждений',
                ! empty($p['unplanned']) => ($p['why'] ?? null) === 'bids' ? 'Подтверждения есть, на аукцион не отправлено' : 'Отправка на аукцион отменена',
                ! empty($p['off']) => ($p['to'] ?? null) === 'sale' ? 'С аукциона в продажу' : 'С аукциона в черновики',
                default => ($p['why'] ?? null) === 'no_bids' ? 'На аукцион: подтверждений не было' : 'Отправлено на аукцион',
            },
            // «Предложено из заявок на приём» (13.10.2026): ТС парковки выставила сама, админ принял.
            OfferEventType::Proposal => ! empty($p['accepted']) ? 'Принято из заявок на приём' : 'Предложено из заявок на приём Park XCar',
            // Номер ушёл вендору в ежедневный запрос (15.10.2026) или метку сняли.
            OfferEventType::Requested => ! empty($p['cleared']) ? 'Номер возвращён в «К запросу»' : 'Номер отправлен вендору в запрос',
            OfferEventType::PickupAssigned => 'Вывоз: '.($p['who'] ?? 'мы').', '.mb_strtolower(Destination::tryFrom($p['to'] ?? '')?->label() ?? ''),
            // Слот: поставили («В слот 4 окт, 16:00»), убрали или часы не смогли выпустить (`error` — чего не хватило).
            OfferEventType::Scheduled => isset($p['error']) ? 'Не вышло в слот: '.$p['error'] : (isset($p['at']) ? 'В слот '.Carbon::parse($p['at'])->translatedFormat('j M, H:i') : 'Убрано из слота'),
            // Окно галереи: открыли («В галерее до 11 окт, 23:59») или убрали руками.
            OfferEventType::Gallery => isset($p['until']) ? 'В галерее до '.Carbon::parse($p['until'])->translatedFormat('j M, H:i') : 'Убрано из галереи',
            OfferEventType::PlaceChanged => 'Автомобиль: '.(CarPlace::labelOf($p['place'] ?? null) ?? 'место не указано'),
            OfferEventType::Note => (string) ($p['text'] ?? ''),
            OfferEventType::Garage => self::garageText($p),
            OfferEventType::RequirementAnswered => 'Менеджер: «'.($p['exit'] ?? '').'»'.(! empty($p['fields']) ? ' — '.implode(', ', $p['fields']) : ''),
            default => $this->type->value,
        };
    }

    /**
     * Правка: «Изменён: закупочная цена 183 840 → 211 200 ₽» (`Changes`, с 14.10.2026; раньше — одни имена полей). Откуда
     * правка — началом строки: Мигторг (поля лота, «ответ до» — тот же конец торгов, что «срок от вендора», или кадры),
     * «Из текста», файл поставщика, своя причина (`why`) — у правок пробником и из других дверей без человека.
     */
    private static function updatedText(array $p): string
    {
        $fields = $p['fields'] ?? [];
        $changes = $p['changes'] ?? [];
        $what = Changes::text($fields, $changes);

        return match (true) {
            ($p['source'] ?? null) === 'migtorg' => 'С Мигторга: '.match (true) {
                isset($p['photos']) => $p['photos'].' фото',
                $fields !== [] => Changes::text(array_values(array_diff($fields, ['answer_by'])), $changes),
                default => 'подтвердили',
            },
            ($p['source'] ?? null) === 'valuation' && ! $changes => 'Оценка из текста: '.implode(', ', array_filter([
                isset($p['value']) ? 'оценочная '.Money::rub((int) $p['value']) : null,
                isset($p['floor']) ? 'закупочная '.Money::rub((int) $p['floor']) : null,
            ])),
            ($p['source'] ?? null) === 'valuation' => 'Оценка из текста: '.$what,
            ($p['source'] ?? null) === 'file' => 'По файлу'.(! empty($p['file']) ? ' «'.$p['file'].'»' : '').': '.$what,
            ! empty($p['why']) => $p['why'].': '.$what,
            default => 'Изменён: '.$what,
        };
    }

    /** Оценка менеджером (11.10.2026): кому отдали, кто оценил, что отклонили и взяли. */
    private static function estimateText(array $p): string
    {
        $names = fn (array $ids) => User::whereIn('id', $ids)->get(['id', 'name'])->map(fn (User $u) => $u->shortName())->implode(', ');
        $one = fn () => isset($p['user']) ? $names([(int) $p['user']]) : '';
        $sum = isset($p['amount']) ? Money::rub((int) $p['amount']) : '';

        return match ($p['do'] ?? null) {
            'asked' => (isset($p['base'])
                ? 'На корректировку '.Money::rub((int) $p['base']).': '.$names($p['users'] ?? [])
                : 'Отдано на оценку: '.$names($p['users'] ?? []))
                .(empty($p['hidden']) ? '' : ', без заявленной: '.$names($p['hidden'])),
            'given' => ! empty($p['agreed']) ? 'Согласен с ценой '.$sum : 'Оценка: '.$sum,
            'kept' => 'Оставлена цена '.$sum,
            'declined' => 'Отказ от оценки: '.$one().', хочет '.$sum.(empty($p['reason']) ? '' : ', «'.$p['reason'].'»'),
            'rejected' => 'Оценка отклонена: '.$one().', '.$sum,
            'taken' => 'Оценка взята: '.$one().', '.$sum,
            'closed' => 'Снято с оценки: '.$names($p['users'] ?? []),
            default => 'Оценка',
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
