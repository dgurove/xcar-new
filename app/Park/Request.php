<?php

namespace App\Park;

use App\Mail\Thread;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'type', 'state', 'thread_id', 'yard_id', 'planned_at', 'done_at', 'contact', 'note', 'assignee_id', 'created_by'])]
class Request extends Model
{
    protected $table = 'park_requests';

    protected function casts(): array
    {
        return ['type' => RequestType::class, 'state' => RequestState::class, 'planned_at' => 'datetime', 'done_at' => 'datetime'];
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

    public function isOpen(): bool
    {
        return $this->state === RequestState::New;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->planned_at?->isPast();
    }
}
