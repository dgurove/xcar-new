<?php

namespace App\Park;

use App\Mail\Message;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пропуск на получение ТС: анкета покупателя и код QR. Заводится, когда покупатель отправил анкету по ссылке
 * `Vehicle::pickupUrl()`; работает после подтверждения страховой (`confirmed_at`), гаснет при выдаче (`used_at`),
 * отказе покупателя или «не покупатель» (`revoked_at`). Живой у ТС один — `Vehicle::pass()`.
 */
#[Fillable(['vehicle_id', 'code', 'name', 'phone', 'email', 'pickup_on', 'submitted_at', 'confirmed_at', 'confirmed_by', 'confirm_note',
    'request_message_id', 'mailed_at', 'used_at', 'used_by', 'revoked_at', 'revoke_reason'])]
class Pass extends Model
{
    protected $table = 'park_passes';

    /** Crockford base32 без I, L, O, U: код читают глазами и вводят руками, путать нечего. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected function casts(): array
    {
        return ['pickup_on' => 'date', 'submitted_at' => 'datetime', 'confirmed_at' => 'datetime', 'mailed_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Случайный код в 20 знаков (100 бит): и для пропуска, и для ссылки на анкету. */
    public static function freshCode(): string
    {
        $code = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $code .= self::ALPHABET[ord($byte) & 31];
        }

        return $code;
    }

    /** Код из того, что прочитал сканер или ввёл человек: адрес пропуска или голый код, любой регистр. */
    public static function codeFrom(string $scanned): ?string
    {
        $s = strtoupper(trim($scanned));
        if (preg_match('~/P/([0-9A-Z]{20})(?:[/?#]|$)~', $s, $m) || preg_match('~^([0-9A-Z]{20})$~', $s, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function byCode(string $scanned): ?self
    {
        $code = self::codeFrom($scanned);

        return $code ? self::where('code', $code)->first() : null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function requestMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'request_message_id');
    }

    /** Адрес в QR — заглавными: так QR кодируется буквенно-цифровым режимом, модули крупнее, старые камеры читают. */
    public function qrText(): string
    {
        return strtoupper(Surface::Site->url('/p/'.$this->code));
    }

    public function url(): string
    {
        return Surface::Site->url('/p/'.$this->code);
    }

    public function isLive(): bool
    {
        return ! $this->revoked_at && ! $this->used_at;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Состояние словом и тоном: для анкеты, страницы скана и сканера. */
    public function status(): array
    {
        return match (true) {
            (bool) $this->used_at => ['Выдан', 'closed'],
            (bool) $this->revoked_at => ['Недействителен', 'danger'],
            $this->isConfirmed() => ['Подтверждено', 'open'],
            default => ['Ждём подтверждения страховой', 'urgent'],
        };
    }

    /** «Иванов И. И.» — на странице, которую откроет любой, кто отсканирует код. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = array_shift($parts) ?? '';

        return trim($first.' '.implode(' ', array_map(fn ($p) => mb_substr($p, 0, 1).'.', $parts)));
    }
}
