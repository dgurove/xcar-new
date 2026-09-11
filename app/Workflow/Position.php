<?php

namespace App\Workflow;

use App\Offers\Offer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Где оффер стоит на ветке маршрута. */
#[Fillable(['offer_id', 'track', 'stage_id', 'entered_at', 'block_entered_at', 'deadline_at', 'reminded_at', 'overdue_at', 'payload'])]
class Position extends Model
{
    protected $table = 'offer_positions';

    protected function casts(): array
    {
        return [
            'track' => Track::class,
            'entered_at' => 'datetime',
            'block_entered_at' => 'datetime',
            'deadline_at' => 'datetime',
            'reminded_at' => 'datetime',
            'overdue_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function isOverdue(): bool
    {
        return $this->deadline_at?->isPast() ?? false;
    }
}
