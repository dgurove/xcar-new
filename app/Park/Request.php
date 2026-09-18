<?php

namespace App\Park;

use App\Mail\Thread;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'type', 'state', 'thread_id', 'yard_id', 'planned_at', 'done_at', 'contact', 'note', 'assignee_id', 'created_by',
    'contact_name', 'contact_phone', 'from_address', 'carrier', 'distance_km', 'cost', 'started_at', 'done_by', 'cancel_reason'])]
class Request extends Model
{
    protected $table = 'park_requests';

    protected function casts(): array
    {
        return ['type' => RequestType::class, 'state' => RequestState::class, 'planned_at' => 'datetime', 'done_at' => 'datetime', 'started_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'yard_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    public function isTow(): bool
    {
        return $this->type === RequestType::Tow;
    }

    /** Контакт, у кого забираем: новые поля, иначе старая строка. */
    public function contactLine(): ?string
    {
        return trim(($this->contact_name ?? '').' '.($this->contact_phone ?? '')) ?: $this->contact;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->planned_at?->isPast();
    }
}
