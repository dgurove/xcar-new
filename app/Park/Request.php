<?php

namespace App\Park;

use App\Mail\Thread;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'type', 'state', 'thread_id', 'yard_id', 'planned_at', 'done_at', 'note', 'assignee_id', 'created_by',
    'contact_name', 'contact_phone', 'from_address', 'carrier', 'distance_km', 'cost', 'started_at', 'done_by', 'cancel_reason', 'delivery', 'contacted_at', 'next_call_at'])]
class Request extends Model
{
    protected $table = 'park_requests';

    protected function casts(): array
    {
        return ['type' => RequestType::class, 'state' => RequestState::class, 'planned_at' => 'datetime', 'done_at' => 'datetime', 'started_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_at' => 'datetime', 'contacted_at' => 'datetime', 'next_call_at' => 'datetime', 'delivery' => Delivery::class];
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

    /** Новая заявка на приём или эвакуацию, по которой ещё не созвонились — или пора перезвонить. */
    public function needsCall(): bool
    {
        return in_array($this->type, [RequestType::Intake, RequestType::Tow], true) && $this->state === RequestState::New
            && (($this->delivery === null && ! $this->contacted_at && ! $this->planned_at) || ($this->next_call_at && $this->next_call_at->lte(now())));
    }

    /** Что делать по заявке следующим — подпись главной кнопки на странице, в карточке и окошке. Закрытая — null. */
    public function verb(): ?string
    {
        return match (true) {
            ! $this->isOpen() => null,
            $this->needsCall() => 'Связались',
            $this->isTow() && $this->state === RequestState::New => 'Назначить',
            $this->isTow() && $this->state === RequestState::Scheduled => 'Выехали',
            $this->isTow() || $this->type === RequestType::Intake => 'Принять',
            // Выдать и переставить можно только ТС на стоянке; иначе главного действия нет — ждём приёма.
            $this->type === RequestType::Release => $this->vehicle?->state === VehicleState::Stored ? 'Выдать' : null,
            $this->type === RequestType::Move => $this->vehicle?->state === VehicleState::Stored ? 'Переставить' : null,
            default => $this->type->verb(),
        };
    }

    public function contactLine(): ?string
    {
        return trim(($this->contact_name ?? '').' '.($this->contact_phone ?? '')) ?: null;
    }

    /**
     * Закрыть сделанным: переданную заявку и все открытые заявки этих типов по ТС — одно действие стоянки
     * закрывает всё, что о нём просило. Исполнитель не перетирается: кто закрыл — `done_by`.
     *
     * @param  list<RequestType>  $types
     */
    public static function closeOpen(Vehicle $vehicle, array $types, User $by, ?self $request = null, ?string $note = null): void
    {
        $done = array_filter(['state' => RequestState::Done, 'done_at' => now(), 'done_by' => $by->id, 'note' => $note]);
        $request?->update($done);
        self::where('vehicle_id', $vehicle->id)->whereIn('type', $types)->whereIn('state', RequestState::open())->update($done);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->planned_at?->isPast();
    }
}
