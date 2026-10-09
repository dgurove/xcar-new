<?php

namespace App\Park;

use App\Mail\Chains\NodeTitle;
use App\Mail\Message;
use App\Mail\Thread;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'type', 'state', 'thread_id', 'yard_id', 'planned_at', 'done_at', 'note', 'assignee_id', 'created_by',
    'contact_name', 'contact_phone', 'done_by', 'cancel_reason', 'letter_at', 'letter_id'])]
class Request extends Model
{
    protected $table = 'park_requests';

    /** Управляющий с городом видит заявки только ТС своего города (`City`). */
    protected static function booted(): void
    {
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? City::requests($q, $city) : null);
    }

    protected function casts(): array
    {
        return ['type' => RequestType::class, 'state' => RequestState::class, 'planned_at' => 'datetime', 'done_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_at' => 'datetime', 'letter_at' => 'datetime'];
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

    /** Письмо, из которого заявка: его адрес и время — в строке «Заявок» (`source`). */
    public function letter(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'letter_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Откуда заявка — для строки списка с первой секунды (владелец 07.10.2026: «чтобы было понятно откуда это
     * появилось»): письмо — адрес того, кто написал (не имя) и время письма; иначе — кто завёл и когда; завела система
     * (восстановление) — только когда.
     *
     * `when` — время всегда с днём: «сегодня, 12:40», «вчера, 9:05», «7 окт, 12:40».
     *
     * @return array{email: ?string, name: ?string, at: ?CarbonInterface, when: ?string}
     */
    public function source(): array
    {
        [$email, $name, $at] = $this->letter
            ? [NodeTitle::email($this->letter), null, $this->letter->date_at ?? $this->created_at]
            : [null, $this->creator?->shortName(), $this->created_at];
        $day = $at?->isToday() ? 'сегодня' : ($at?->isYesterday() ? 'вчера' : $at?->translatedFormat($at->isCurrentYear() ? 'j M' : 'j M Y'));

        return ['email' => $email, 'name' => $name, 'at' => $at, 'when' => $at ? $day.', '.$at->format('G:i') : null];
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * Что делать по заявке следующим — подпись главной кнопки в карточке и деле. Закрытая — null. Приём — только
     * «Принять» (владелец 10.10.2026: «тут чисто приём и всё» — звонка, эвакуации и даты приезда больше нет).
     */
    public function verb(): ?string
    {
        return match (true) {
            ! $this->isOpen() => null,
            $this->type === RequestType::Intake => 'Принять',
            // Выдать и переставить можно только ТС на стоянке; иначе главного действия нет — ждём приёма.
            $this->type === RequestType::Release => $this->vehicle?->state === VehicleState::Stored ? 'Выдать' : null,
            $this->type === RequestType::Move => $this->vehicle?->state === VehicleState::Stored ? 'Переставить' : null,
            default => $this->type->verb(),
        };
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
