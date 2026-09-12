<?php

namespace App\Chats;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Обращение гостя живёт в cookie «{id}.{токен}» на год. Cookie зашифрована
 * общим ключом, в базе — только hash токена.
 */
final class GuestEnquiry
{
    public const COOKIE = 'xcar_enquiry';

    private const MINUTES = 60 * 24 * 365;

    public function token(Request $request): ?string
    {
        [, $token] = $this->parse($request);

        return $token;
    }

    public function chat(Request $request): ?Chat
    {
        [$id, $token] = $this->parse($request);
        if (! $id) {
            return null;
        }
        $chat = Chat::whereKey($id)->whereNull('offer_id')->first();

        return $chat?->allows(null, $token) ? $chat : null;
    }

    public function issue(Chat $chat, string $plain): void
    {
        Cookie::queue(self::COOKIE, "{$chat->id}.{$plain}", self::MINUTES);
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** @return array{0: ?int, 1: ?string} */
    private function parse(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);
        if (! is_string($raw) || ! preg_match('/^(\d+)\.([A-Za-z0-9]{40})$/', $raw, $m)) {
            return [null, null];
        }

        return [(int) $m[1], $m[2]];
    }
}
