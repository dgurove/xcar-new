<?php

namespace App\Park;

use App\Cars\HasLabels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Что за кадр в деле ТС. Отдельно от происхождения (`custom_properties.source`: `mail` — приехал из письма,
 * `app` — сняли в приложении): фото от страховой и наши фото приёма приезжают одинаково, а значат разное.
 */
enum PhotoStage: string
{
    use HasLabels;

    case Vendor = 'vendor';
    case Intake = 'intake';
    case Pickup = 'pickup';
    case Release = 'release';

    public function label(): string
    {
        return match ($this) {
            self::Vendor => 'Фото от страховой',
            self::Intake => 'Фото при приёме',
            self::Pickup => 'Фото при погрузке',
            self::Release => 'Фото при выдаче',
        };
    }

    /** Кадр без метки — из старых писем, то есть от страховой. */
    public static function of(Media $media): self
    {
        return self::tryFrom((string) $media->getCustomProperty('stage')) ?? self::Vendor;
    }
}
