<?php

namespace App\Park;

use App\Mail\Message;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отчёт о приёме вендору (29.10.2026, Никита: «работает через раз, ошибки при отправлении, Альфа не видит вложений»): что
 * уходит — номера кадров и документов ТС, уже лежащих у машины (форма грузит файлы по одному заранее), кому и каким текстом.
 * `queued` — письма собирает очередь (`Jobs\SendIntakeReport`) и шлёт SMTP; `sent` — все письма отчёта ушли
 * (`Listeners\ReportDelivery`, тогда же шаг «Отчёт»); `failed` — не собрались или не ушли, причина в `error`, «Повторить».
 * `messages` — письма отчёта (частями «ч.1, ч.2»).
 */
#[Fillable(['vehicle_id', 'user_id', 'parent_id', 'to', 'cc', 'text', 'photos', 'docs', 'state', 'error', 'messages', 'sent_at'])]
class Report extends Model
{
    use FastAttributes;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Отчёт в пути дольше — задача умерла молча (выкладка посреди сборки): новый отправить можно. */
    public const STALE_MINUTES = 15;

    protected $table = 'park_reports';

    protected function casts(): array
    {
        return ['photos' => 'array', 'docs' => 'array', 'messages' => 'array', 'sent_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    /** Отчёт ТС, что сейчас в пути: второй, пока он идёт, не заводится (двойное нажатие, старая вкладка). */
    public static function inFlight(Vehicle $vehicle): ?self
    {
        return self::where('vehicle_id', $vehicle->id)->where('state', self::QUEUED)
            ->where('updated_at', '>', now()->subMinutes(self::STALE_MINUTES))->latest('id')->first();
    }

    /** Последний отчёт ТС, если он не ушёл и после него другого не было: «Не ушло — Повторить». */
    public static function lastFailed(Vehicle $vehicle): ?self
    {
        $last = self::where('vehicle_id', $vehicle->id)->latest('id')->first();

        return $last?->state === self::FAILED ? $last : null;
    }

    /** Отчёт этого письма (письма отчёта — `messages`). */
    public static function ofMessage(Message $message): ?self
    {
        return self::whereJsonContains('messages', $message->id)->first();
    }

    public function photoCount(): int
    {
        return count($this->photos ?? []);
    }

    public function docCount(): int
    {
        return count(array_merge(...array_values($this->docs ?: [[]])));
    }
}
