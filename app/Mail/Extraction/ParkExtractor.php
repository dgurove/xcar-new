<?php

namespace App\Mail\Extraction;

use App\Cars\Brand;
use App\Mail\Extraction\Templates\Generic;
use App\Support\Phone;
use App\Vendors\Vendor;

/**
 * Письмо о хранении: тело у страховых шаблонное («просьба связаться с
 * клиентом»), машины в нём нет — есть номер убытка, телефоны и, если
 * повезло, VIN с госномером. Марку и год вписывает приёмщик при осмотре.
 */
final class ParkExtractor
{
    /** Формы номеров, которые встречаются только в переписке стоянки. */
    private const REFS = ['\d{4}-\d{7}-\d{2}', '\d{10}', '\d{8}'];

    public function extract(?string $subject, ?string $body, ?string $fromEmail = null, ?\DateTimeInterface $on = null): array
    {
        $text = QuotationStripper::strip($body);
        $sender = QuotationStripper::forwardedSender($body) ?? $fromEmail;
        $vendor = Vendor::forSender($sender);
        $class = $vendor?->parser?->templateClass() ?? Generic::class;
        $fields = (new $class)->extract(trim((string) $subject), $text);
        if (isset($fields['floor_price'])) {
            $fields['value'] = $fields['floor_price'];   // «оценены N» — стоимость в акт, не цена
        }
        unset($fields['year'], $fields['floor_price']);   // год в теле — всегда год письма
        // Марка из темы стоянки — почти всегда слова письма («передача ТС»): оставляем только известную справочнику.
        if (isset($fields['brand']) && ! Brand::query()->whereRaw('lower(name) = ?', [mb_strtolower($fields['brand']['value'])])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower($fields['brand']['value'])])->exists()) {
            unset($fields['brand'], $fields['model']);
        }
        // «ТС Hyundai Solaris гос. номер» — марка с моделью после «ТС» в теле.
        if (! isset($fields['brand']) && preg_match('/\bТС\s+([A-Za-zА-Яа-яЁё-]{2,})\s+([A-Za-zА-Яа-яЁё0-9-]{1,20})/u', $text, $m)
            && Brand::query()->whereRaw('lower(name) = ?', [mb_strtolower($m[1])])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower($m[1])])->exists()) {
            $fields['brand'] = ['value' => $m[1], 'source' => 'body'];
            $fields['model'] = ['value' => $m[2], 'source' => 'body'];
        }
        if (! isset($fields['code'])) {
            foreach ([trim((string) $subject), $text] as $haystack) {
                if ($ref = $this->ref($haystack)) {
                    $fields['code'] = ['value' => $ref, 'source' => 'text'];
                    break;
                }
            }
        }
        if ($phones = $this->phones($text)) {
            $fields['phones'] = ['value' => $phones, 'source' => 'body'];
        }
        if (preg_match('/цвет\s*:?\s*([а-яё\- ]{3,20})/iu', $text, $m)) {
            $fields['color'] = ['value' => mb_strtolower(trim($m[1])), 'source' => 'body'];
        }
        $fields += Templates\Template::common(trim((string) $subject), $text, $on);
        if ($vendor) {
            $fields['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
            $fields['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
        }
        $fields['sender'] = ['value' => $sender, 'source' => 'sender'];

        return $fields;
    }

    public static function looksLikeRequest(array $fields): bool
    {
        return isset($fields['code']) || isset($fields['vin']) || isset($fields['plate']);
    }

    private function ref(string $text): ?string
    {
        $normalized = Code::normalize($text) ?? '';
        foreach (self::REFS as $pattern) {
            if (preg_match('/(?<![A-Z0-9\/\-.])(?:'.$pattern.')(?![A-Z0-9\/\-.])/u', $normalized, $m)) {
                return $m[0];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function phones(string $text): array
    {
        preg_match_all('/(?:\+?7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/u', $text, $m);

        return array_values(array_unique(array_map(fn ($p) => Phone::format(Phone::normalize($p) ?? $p), $m[0])));
    }
}
