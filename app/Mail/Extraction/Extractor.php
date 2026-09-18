<?php

namespace App\Mail\Extraction;

use App\Mail\Extraction\Templates\Generic;
use App\Mail\Extraction\Templates\Template;
use App\Vendors\Vendor;

/** Машина из письма: настоящий отправитель → вендор по адресу или домену → его шаблон разбора. */
final class Extractor
{
    public function extract(?string $subject, ?string $body, ?string $fromEmail = null, ?\DateTimeInterface $on = null): array
    {
        $text = QuotationStripper::strip($body);
        $sender = QuotationStripper::forwardedSender($body) ?? $fromEmail;
        $vendor = Vendor::forSender($sender);
        $class = $vendor?->parser?->templateClass() ?? Generic::class;
        /** @var Template $template */
        $template = new $class;
        $fields = $template->extract(trim((string) $subject), $text);
        $fields += Template::common(trim((string) $subject), $text, $on);
        if ($vendor) {
            $fields['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
            $fields['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
        }
        $fields['sender'] = ['value' => $sender, 'source' => 'sender'];

        return $fields;
    }

    /** Код убытка с машиной — уже оффер; машина с ценой — тоже. Переписка по существующему офферу отсекается снаружи. */
    public static function looksLikeOffer(array $fields): bool
    {
        $hasCar = isset($fields['vin']) || isset($fields['plate']) || isset($fields['brand']);
        $hasCode = isset($fields['code']);

        return ($hasCode && $hasCar) || ($hasCar && isset($fields['floor_price']));
    }
}
