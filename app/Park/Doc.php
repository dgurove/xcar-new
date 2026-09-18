<?php

namespace App\Park;

use App\Mail\Thread;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Бумага по ТС: что и когда отправили вендору или получили от него, со сканом и письмом. */
#[Fillable(['vehicle_id', 'kind', 'direction', 'state', 'at', 'user_id', 'media_id', 'thread_id', 'note'])]
class Doc extends Model
{
    protected $table = 'park_vehicle_docs';

    protected function casts(): array
    {
        return ['kind' => DocKind::class, 'state' => DocState::class, 'at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOut(): bool
    {
        return $this->direction === 'out';
    }

    public function isDone(): bool
    {
        return $this->state !== DocState::Pending;
    }
}
