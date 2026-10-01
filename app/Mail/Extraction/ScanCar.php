<?php

namespace App\Mail\Extraction;

use App\Cars\Brand;
use App\Cars\Names;
use App\Cars\Vin\VinDecoder;

/**
 * Марка и модель из слов документа, чаще скана после OCR: «СНАМСАМ | CS35PLUS_», «СЕЕГУ MONJARO», «Хавал НЗ».
 * Марка — словарём как есть, латиницей (`Names::latin`), с опечаткой (`Names::brandNear`) или по модели, которая
 * бывает только у одной марки (`Names::brandByModel`); по VIN (`brandOfVin`) — только когда слов нет вовсе.
 * Модель — известная справочнику марки (пробелы и одна-две опечатки не мешают) или код с цифрой латиницей
 * («27772A», «X70»); незнакомое слово из скана («МОМТАКО», «ух») моделью не становится — пусть впишут руками.
 */
final class ScanCar
{
    /** @return array{brand: string, model: ?string}|null */
    public static function of(string $words): ?array
    {
        $words = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[|_©®°‘’“”"`*~]+/u', ' ', $words)));
        $latin = Names::latin($words);
        $brand = null;
        $rest = '';
        foreach (array_unique([$words, $latin]) as $variant) {
            if ($variant !== '' && ($found = Names::find($variant))) {
                [$brand, $rest] = [$found['brand'], trim(($found['model'] ?? '').' '.$found['after'])];
                break;
            }
        }
        $tokens = $latin === '' ? [] : explode(' ', $latin);
        if (! $brand && $tokens && ($near = Names::brandNear($tokens[0]))) {
            [$brand, $rest] = [$near, implode(' ', array_slice($tokens, 1))];
        }
        if (! $brand) {
            foreach ($tokens as $i => $token) {
                if ($byModel = Names::brandByModel($token)) {
                    [$brand, $rest] = [$byModel, implode(' ', array_slice($tokens, $i))];
                    break;
                }
            }
        }

        return $brand ? ['brand' => $brand->name, 'model' => self::model($brand, $rest)] : null;
    }

    /** Марка по VIN — только когда декодер уверен (схема завода, а не общий WMI концерна: LVT — и Chery, и Exeed). */
    public static function brandOfVin(string $vin): ?Brand
    {
        $result = app(VinDecoder::class)->decode($vin);
        $name = $result->get('brand');
        if (! is_string($name) || $result->confidence('brand') !== 'high') {
            return null;
        }

        return Names::brand($name) ?? Names::find($name)['brand'] ?? null;
    }

    private static function model(Brand $brand, string $words): ?string
    {
        $tokens = array_values(array_filter(explode(' ', trim((string) preg_replace('/\s+/u', ' ', $words))), fn ($t) => $t !== ''));
        for ($len = min(3, count($tokens)); $len >= 1; $len--) {
            $phrase = implode(' ', array_slice($tokens, 0, $len));
            // «Нз» — словарь уже сделал из «НЗ» слово с заглавной; заглавными это снова H3.
            foreach (array_unique([$phrase, Names::latin($phrase), Names::latin(mb_strtoupper($phrase))]) as $variant) {
                if ($known = Names::knownModel($brand, $variant)) {
                    return $known;
                }
            }
        }
        $first = $tokens[0] ?? '';
        $code = Names::latin($first);

        return preg_match('/^[A-Z0-9][A-Z0-9\-]{0,11}$/i', $code) && preg_match('/\d/', $code) ? strtoupper($code) : (preg_match('/^[A-Za-z][A-Za-z\-]{1,15}$/', $first) && $code === $first ? $first : null);
    }
}
