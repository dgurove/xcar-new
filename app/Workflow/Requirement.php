<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/** Просьба к менеджеру на этапе, где его ход. Ответ — нажатая кнопка плюс поля или документ. */
#[Fillable(['offer_id', 'deal_id', 'stage_id', 'user_id', 'title', 'text', 'asks', 'fields', 'due_at', 'done_at', 'answer'])]
class Requirement extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected function casts(): array
    {
        return [
            'asks' => Asks::class,
            'fields' => 'array',
            'answer' => 'array',
            'due_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('files')->useDisk('private');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }
}
