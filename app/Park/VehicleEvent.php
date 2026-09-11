<?php

namespace App\Park;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'user_id', 'type', 'payload'])]
class VehicleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'park_vehicle_events';

    protected function casts(): array
    {
        return ['type' => EventType::class, 'payload' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function text(): string
    {
        $p = $this->payload ?? [];

        return match ($this->type) {
            EventType::Created => 'Заведена',
            EventType::Accepted => 'Принята'.(! empty($p['yard']) ? ' на «'.$p['yard'].'»' : ''),
            EventType::Moved => 'Переставлена'.(! empty($p['from']) ? ' с «'.$p['from'].'»' : '').(! empty($p['to']) ? ' на «'.$p['to'].'»' : ''),
            EventType::Inspected => 'Осмотрена'.(! empty($p['note']) ? ': '.$p['note'] : ''),
            EventType::Towed => 'Эвакуация'.(! empty($p['note']) ? ': '.$p['note'] : ''),
            EventType::Released => 'Выдана',
            EventType::Note => (string) ($p['text'] ?? ''),
            EventType::Updated => 'Изменена: '.implode(', ', $p['fields'] ?? []),
        };
    }
}
