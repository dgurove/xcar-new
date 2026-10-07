<?php

namespace App\Users\Access;

use RuntimeException;

/**
 * Код не принят. `reason`: wait — кнопку в Telegram ещё не нажали, wrong — цифры не те, gone — код устарел, погашен
 * или «Это не я», key — ключ браузера не подошёл. Текст — для поля под кодом.
 */
final class CodeRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return match ($this->reason) {
            'wait' => 202,
            'gone' => 410,
            default => 422,
        };
    }

    public function toResponse()
    {
        return response()->json(['state' => $this->reason, 'error' => $this->getMessage() ?: null], $this->status());
    }
}
