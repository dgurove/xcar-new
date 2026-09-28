<?php

namespace App\Park;

use App\Support\Qr;

/**
 * Картинка QR пропуска: SVG для страниц (чёткий на любом экране), PNG для письма (почтовики SVG не показывают).
 * Уровень коррекции M (общий `Support\Qr`): экран телефона с бликом и трещиной ещё читается, а модули остаются крупными.
 */
final class PassQr
{
    public static function svg(Pass $pass): string
    {
        return Qr::svg($pass->qrText());
    }

    public static function png(Pass $pass): string
    {
        return Qr::png($pass->qrText());
    }
}
