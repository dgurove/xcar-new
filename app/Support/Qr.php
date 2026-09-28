<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR любой строки: SVG для экрана, PNG в data-URI для PDF (dompdf SVG из строки не рисует). */
final class Qr
{
    public static function svg(string $text): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG, 'eccLevel' => EccLevel::M, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'drawLightModules' => false, 'connectPaths' => true, 'quietzoneSize' => 2,
        ])))->render($text);
    }

    /** PNG байтами: для письма (почтовики SVG не показывают). */
    public static function png(string $text, int $scale = 12, int $quiet = 3): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG, 'eccLevel' => EccLevel::M, 'outputBase64' => false, 'scale' => $scale, 'quietzoneSize' => $quiet,
        ])))->render($text);
    }

    public static function pngDataUri(string $text, int $scale = 5): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($text, $scale, 2));
    }
}
