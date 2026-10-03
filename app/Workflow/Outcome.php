<?php

namespace App\Workflow;

use App\Offers\Deal;
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
     * «Подтверждение принято» — исход, которым маршрут догоняет AcceptBid. Кнопкой его не жмут: сделку заводит
     * только «Принять» у самого подтверждения, иначе предложение уходит «в сделку» без сделки и менеджера.
     */
    /**
     * Годится ли исход этой сделке: гаражный — только гаражной, где поставщику платим мы, покупательский — всем
     * остальным, без ветки — всем. Нет сделки (черновик, приём) — гаражных исходов не видно.
     */
    public function fits(?Deal $deal): bool
    {
        $garage = $deal?->isGarageUs() ?? false;

        return match ($this->branch) {
            self::GARAGE => $garage,
            self::BUYER => ! $garage,
            default => true,
        };
    }

    public function acceptsBid(): bool
    {
        return mb_strtolower(trim($this->label)) === 'подтверждение принято';
    }
}
