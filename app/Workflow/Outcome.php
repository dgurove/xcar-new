<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\Destination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Исход — кнопка, уводящая с этапа на другой этап. */
#[Fillable(['stage_id', 'to_stage_id', 'label', 'actor', 'confirm', 'position', 'branch'])]
class Outcome extends Model
{
    protected $table = 'workflow_exits';

    /** Ветка гаража: машину забирают в гараж, поставщику платим мы. */
    public const GARAGE = 'garage';

    /** Ветка покупателя: всем сделкам, кроме гаражной «платим мы». */
    public const BUYER = 'buyer';

    /** Ветка передачи: автомобиль забирает сам менеджер сделки (`Deal::buyerPicksUp`). */
    public const BUYER_PICKS = 'picks';

    /** Ветка передачи: отдаём мы — со своей парковки или выдачей от поставщика. */
    public const WE_HAND = 'hands';

    /**
     * Ветка гаражной сделки любого плательщика (06.10.2026): бумаги готовы — сделка закрыта, машину не передаём —
     * её везёт вывоз к менеджеру в гараж. Передача (`picks`, `hands`) гаражным не подходит.
     */
    public const KEEPS = 'keeps';

    /** Ветка обычной сделки — не гаражной (у Каркаде передача с площадки, где развилки «кто забирает» нет). */
    public const DEAL = 'deal';

    /**
     * Согласие менеджера (10.10.2026, владелец: «Покупаю» → «Беру в работу», «Отказываюсь» → «Я передумал», приглушённой).
     * Прежние надписи узнаём тоже: маршрут в базе правится миграцией.
     */
    public const TAKES = ['беру в работу', 'покупаю'];

    /** Ветка оплаты через ООО «Р.О.С.» (Альфа с НДС, 10.10.2026): деньги пришли на Р.О.С. — сначала переводим Альфе. */
    public const ROS = 'ros';

    /** Ветка оплаты не через Р.О.С.: деньги у страховой сразу (напрямую) или у ПРАЙМ. */
    public const NOT_ROS = 'not_ros';

    /** Ветки продажи: в маршруте вывоза их не бывает (`Workflow::problems`). */
    public const SALE_BRANCHES = [self::GARAGE, self::BUYER, self::BUYER_PICKS, self::WE_HAND, self::KEEPS, self::DEAL, self::ROS, self::NOT_ROS];

    protected function casts(): array
    {
        return ['actor' => Actor::class];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }

    /**
     * Годится ли исход: на продаже — этой сделке (гаражный — только гаражной, где поставщику платим мы,
     * покупательский — всем остальным; нет сделки — гаражных не видно; передача — «забирает сам» только сделке, где
     * менеджер и вывозчик одно лицо, «отдаём мы» — всем остальным, кроме гаражных: те кончаются бумагами, `keeps`), на
     * вывозе — месту назначения (`Destination`:
     * ветки keeper, ours; не назначено — к нам). Без ветки — всем.
     */
    public function fits(Deal|Destination|null $for): bool
    {
        $garage = $for instanceof Deal && $for->isGarageUs();
        $keeps = $for instanceof Deal && $for->isGarage();
        $picks = $for instanceof Deal && $for->buyerPicksUp();
        $to = $for instanceof Destination ? $for : Destination::Ours;
        $ros = $for instanceof Deal && $for->schemeOf() === \App\Offers\DealScheme::Ros;

        return match ($this->branch) {
            null, '' => true,
            self::GARAGE => $garage,
            self::BUYER => ! $garage,
            self::BUYER_PICKS => $picks && ! $keeps,
            self::WE_HAND => ! $picks && ! $keeps,
            self::KEEPS => $keeps,
            self::DEAL => ! $keeps,
            self::ROS => $ros,
            self::NOT_ROS => ! $ros,
            default => $to->value === $this->branch,
        };
    }

    /** Согласие менеджера взять сделку в работу. */
    public function takes(): bool
    {
        return $this->actor === Actor::Manager && in_array(mb_strtolower(trim($this->label)), self::TAKES, true);
    }

    /**
     * Отступной исход менеджера — «Я передумал»: ведёт назад в приём подтверждений (сделка отменяется). На странице сделки
     * — тихой строкой под главной кнопкой, а не второй кнопкой рядом.
     */
    public function isRetreat(): bool
    {
        return $this->actor === Actor::Manager && $this->to?->offer_state === \App\Offers\OfferState::Open;
    }

    /**
     * «Подтверждение принято» — исход, которым маршрут догоняет AcceptBid. Кнопкой его не жмут: сделку заводит
     * только «Принять» у самого подтверждения, иначе предложение уходит «в сделку» без сделки и менеджера.
     */
    public function acceptsBid(): bool
    {
        return mb_strtolower(trim($this->label)) === 'подтверждение принято';
    }
}
