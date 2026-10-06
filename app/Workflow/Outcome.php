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

    /** Ветки продажи: в маршруте вывоза их не бывает (`Workflow::problems`). */
    public const SALE_BRANCHES = [self::GARAGE, self::BUYER, self::BUYER_PICKS, self::WE_HAND, self::KEEPS, self::DEAL];

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
     * ветки yard, keeper, ours; не назначено — парковка, как было). Без ветки — всем.
     */
    public function fits(Deal|Destination|null $for): bool
    {
        $garage = $for instanceof Deal && $for->isGarageUs();
        $keeps = $for instanceof Deal && $for->isGarage();
        $picks = $for instanceof Deal && $for->buyerPicksUp();
        $to = $for instanceof Destination ? $for : Destination::Yard;

        return match ($this->branch) {
            null, '' => true,
            self::GARAGE => $garage,
            self::BUYER => ! $garage,
            self::BUYER_PICKS => $picks && ! $keeps,
            self::WE_HAND => ! $picks && ! $keeps,
            self::KEEPS => $keeps,
            self::DEAL => ! $keeps,
            default => $to->value === $this->branch,
        };
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
