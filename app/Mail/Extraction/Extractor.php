<?php

namespace App\Mail\Extraction;

use App\Mail\Extraction\Templates\Energogarant;
use App\Mail\Extraction\Templates\Generic;
use App\Mail\Extraction\Templates\Insight;
use App\Mail\Extraction\Templates\Sovcombank;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Extraction\Templates\Tinkoff;

/** Машина из письма: настоящий отправитель → домен → шаблон страховой. */
final class Extractor
{
    private const TEMPLATES = [
        'tinsurance.ru' => Tinkoff::class,
        'tinkoffinsurance.ru' => Tinkoff::class,
        'sovcomins.ru' => Sovcombank::class,
        'msk-garant.ru' => Energogarant::class,
        'energogarant.ru' => Energogarant::class,
        'insightins.ru' => Insight::class,
    ];

    /** Домен → название страховой в справочнике, чтобы кандидат сразу знал её. */
    private const INSURERS = [
        'tinsurance.ru' => 'Т-Страхование',
        'tinkoffinsurance.ru' => 'Т-Страхование',
        'sovcomins.ru' => 'Совкомбанк Страхование',
        'msk-garant.ru' => 'Энергогарант',
        'energogarant.ru' => 'Энергогарант',
        'insightins.ru' => 'ИНСАЙТ',
        'alfastrah.ru' => 'АльфаСтрахование',
    ];

    public function extract(?string $subject, ?string $body, ?string $fromEmail = null): array
    {
        $text = QuotationStripper::strip($body);
        $sender = QuotationStripper::forwardedSender($body) ?? $fromEmail;
        $domain = $sender ? mb_strtolower((string) preg_replace('/.*@/u', '', $sender)) : '';
        $class = self::TEMPLATES[$domain] ?? Generic::class;
        /** @var Template $template */
        $template = new $class;
        $fields = $template->extract(trim((string) $subject), $text);
        if (isset(self::INSURERS[$domain])) {
            $fields['insurer'] = ['value' => self::INSURERS[$domain], 'source' => 'sender'];
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
