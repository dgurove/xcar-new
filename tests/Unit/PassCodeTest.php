<?php

namespace Tests\Unit;

use App\Park\Pass;
use PHPUnit\Framework\TestCase;

/**
 * Код пропуска из того, что прочитал сканер. Ошибка здесь молчалива и дорога: чужая ссылка, принятая за код,
 * или наш код, не узнанный в адресе, — это выдача не тому или отказ тому.
 */
class PassCodeTest extends TestCase
{
    public function test_code_from_qr_url_and_bare_code(): void
    {
        $this->assertSame('BSNTJN3F0APEX90DJA1N', Pass::codeFrom('HTTPS://XCAR.RU/P/BSNTJN3F0APEX90DJA1N'));
        $this->assertSame('BSNTJN3F0APEX90DJA1N', Pass::codeFrom('https://xcar.ru/p/bsntjn3f0apex90dja1n'));
        $this->assertSame('BSNTJN3F0APEX90DJA1N', Pass::codeFrom(' BSNTJN3F0APEX90DJA1N '));
    }

    public function test_foreign_text_is_not_a_code(): void
    {
        $this->assertNull(Pass::codeFrom('https://example.com/menu'));
        $this->assertNull(Pass::codeFrom('HTTPS://XCAR.RU/P/BSNTJN3F0APEX90DJA1'));
        $this->assertNull(Pass::codeFrom('HTTPS://XCAR.RU/P/BSNTJN3F0APEX90DJA1NX'));
        $this->assertNull(Pass::codeFrom(''));
    }

    public function test_fresh_codes_are_twenty_crockford_chars(): void
    {
        $code = Pass::freshCode();
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{20}$/', $code);
        $this->assertNotSame($code, Pass::freshCode());
    }
}
