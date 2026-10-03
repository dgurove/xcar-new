<?php

namespace Tests\Unit;

use App\Telegram\Offers\LoginUrl;
use PHPUnit\Framework\TestCase;

/**
 * Подпись кнопки входа Telegram. Ошибка здесь молчалива и дорога: пропустим чужую подпись — войдёт кто угодно по
 * подобранной ссылке; не узнаем свою — менеджер молча окажется на странице входа.
 */
class OffersBotLoginTest extends TestCase
{
    private const TOKEN = '123456:TEST-token';

    private function signed(array $data): array
    {
        ksort($data);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($data), $data));

        return $data + ['hash' => hash_hmac('sha256', $check, hash('sha256', self::TOKEN, true))];
    }

    public function test_valid_signature_gives_telegram_id(): void
    {
        $q = $this->signed(['id' => '777', 'first_name' => 'Дмитрий', 'username' => 'dg', 'auth_date' => (string) time()]);
        $this->assertSame(777, LoginUrl::verify($q, self::TOKEN));
        // Наш параметр к адресу подпись не ломает и не подменяет.
        $this->assertSame(777, LoginUrl::verify($q + ['to' => 'https://evil.example'], self::TOKEN));
    }

    public function test_forged_stale_or_foreign_signature_is_rejected(): void
    {
        $q = $this->signed(['id' => '777', 'auth_date' => (string) time()]);
        $this->assertNull(LoginUrl::verify(['id' => '778'] + $q, self::TOKEN));
        $this->assertNull(LoginUrl::verify($q, '999:other-bot'));
        $this->assertNull(LoginUrl::verify($this->signed(['id' => '777', 'auth_date' => (string) (time() - 2 * 86400)]), self::TOKEN));
        $this->assertNull(LoginUrl::verify(['id' => '777', 'auth_date' => (string) time()], self::TOKEN));
    }
}
