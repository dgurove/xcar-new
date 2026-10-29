<?php

namespace App\Users\Access;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Код от бота: вход (`login`) или продолжение работы (`unlock`). Шесть цифр, в базе — HMAC, живёт TTL секунд, не больше
 * ATTEMPTS неверных вводов, срабатывает один раз. Принимает его только браузер, который просил: id лежит в его cookie
 * `Lock::CODE_COOKIE`, — продиктованный по телефону код в чужом браузере не подойдёт. Кнопка «Продолжить» под
 * сообщением ставит `approved_at`: браузер заканчивает вход сам, подписав `nonce` своим ключом.
 */
#[Fillable(['user_id', 'purpose', 'topic', 'code_hash', 'nonce', 'attempts', 'label', 'chat_id', 'message_id', 'approved_at', 'denied_at', 'used_at', 'expires_at'])]
class AccessCode extends Model
{
    use FastAttributes;

    public const TTL = 300;

    /**
     * Привязка Telegram из окна замка (`link`): кода с цифрами нет, ссылка на бота несёт номер этой записи, и `/start`
     * с ней ставит `approved_at` — браузер, который просил, заканчивает сам. Пока человек ставит Telegram и жмёт
     * «Запустить», проходит дольше пяти минут.
     */
    public const LINK_TTL = 900;

    public const ATTEMPTS = 5;

    /** Новый код тому же человеку — не чаще. */
    public const RESEND = 30;

    protected $connection = 'pgsql_async';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'denied_at' => 'datetime', 'used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $code, string $nonce): string
    {
        return hash_hmac('sha256', $code, 'access-code|'.$nonce.'|'.config('app.key'));
    }

    public function isLive(): bool
    {
        return $this->used_at === null && $this->denied_at === null && $this->attempts < self::ATTEMPTS && $this->expires_at->isFuture();
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->code_hash, self::hash($code, $this->nonce));
    }
}
