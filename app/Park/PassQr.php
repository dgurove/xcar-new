<?php

namespace App\Park;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Картинка QR пропуска: SVG для страниц (чёткий на любом экране), PNG для письма (почтовики SVG не показывают).
 * Уровень коррекции M: экран телефона с бликом и трещиной ещё читается, а модули остаются крупными.
 */
final class PassQr
{
    public static function svg(Pass $pass): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG, 'eccLevel' => EccLevel::M, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'drawLightModules' => false, 'connectPaths' => true, 'quietzoneSize' => 2,
        ])))->render($pass->qrText());
    }

    public static function png(Pass $pass): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG, 'eccLevel' => EccLevel::M, 'outputBase64' => false,
            'scale' => 12, 'quietzoneSize' => 3,
        ])))->render($pass->qrText());
    }
}
