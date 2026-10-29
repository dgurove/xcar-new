<?php

namespace App\Park;

use App\Mail\Message;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Отчёт о приёме вендору (29.10.2026, Никита: «работает через раз, ошибки при отправлении, Альфа не видит вложений»): что
 * уходит — номера кадров и документов ТС, уже лежащих у машины (форма грузит файлы по одному заранее), кому и каким текстом.
 * `queued` — письма собирает очередь (`Jobs\SendIntakeReport`) и шлёт SMTP; `sent` — все письма отчёта ушли
 * (`Listeners\ReportDelivery`, тогда же шаг «Отчёт»); `failed` — не собрались или не ушли, причина в `error`, «Повторить».
 * `messages` — письма отчёта (частями «ч.1, ч.2»), `attempt` — номер сборки: задача берёт отчёт, только если номер её.
 */
#[Fillable(['vehicle_id', 'user_id', 'parent_id', 'to', 'cc', 'text', 'photos', 'docs', 'state', 'error', 'messages', 'sent_at', 'attempt'])]
class Report extends Model
{
    use FastAttributes;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Не собран дольше — задача сборки умерла молча (выкладка посреди сборки, она живёт до 5 минут): «не ушёл, Повторить» (`ReportDelivery::settle`). */
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

    /**
     * Отчёт ТС, что сейчас в пути: второй, пока он идёт, не заводится (двойное нажатие, старая вкладка). Срока здесь нет:
     * медленная очередь пускала второй отчёт; застрявший снимают часы (`ReportDelivery::settle`).
     */
    public static function inFlight(Vehicle $vehicle): ?self
    {
        return self::where('vehicle_id', $vehicle->id)->where('state', self::QUEUED)->latest('id')->first();
    }

    /** Последний отчёт ТС, если он не ушёл и после него другого не было: «Не ушло — Повторить». */
    public static function lastFailed(Vehicle $vehicle): ?self
    {
        $last = self::where('vehicle_id', $vehicle->id)->latest('id')->first();

        return $last?->state === self::FAILED ? $last : null;
    }

    /** Отчёт этого письма (письма отчёта — `messages`, индекс GIN). */
    public static function ofMessage(Message $message): ?self
    {
        return self::whereJsonContains('messages', $message->id)->first();
    }

    /** Задача сборки берёт отчёт: свою попытку, ещё в пути и без писем — опоздавшая после «Повторить» второй комплект не соберёт. */
    public function claim(int $attempt): bool
    {
        return self::whereKey($this->id)->where('state', self::QUEUED)->where('attempt', $attempt)->whereNull('messages')
            ->update(['updated_at' => now()]) === 1;
    }

    public function photoCount(): int
    {
        return count($this->photos ?? []);
    }

    /** Документов: снимки одного «Снять» (`scan`) — один документ, как их склеит сборка. */
    public function docCount(): int
    {
        $ids = array_merge(...array_values($this->docs ?: [[]]));

        return $ids ? Media::whereKey($ids)->get(['id', 'custom_properties'])->unique(fn (Media $m) => $m->getCustomProperty('scan') ?: 'id'.$m->id)->count() : 0;
    }
}
