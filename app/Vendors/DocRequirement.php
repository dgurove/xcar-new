<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** Что вендор просит прислать после приёма ТС — из писем: «акт п/п к соглашению, акт хранения и фото цветным сканом», «два акта и два соглашения». */
enum DocRequirement: string
{
    use HasLabels;

    case HandoverAct = 'handover_act';
    case StorageAct = 'storage_act';
    case Agreement = 'agreement';
    case TwoCopies = 'two_copies';
    case ColorScan = 'color_scan';
    case SignedScan = 'signed_scan';
    case Photos = 'photos';

    public function label(): string
    {
        return match ($this) {
            self::HandoverAct => 'Акт приёма-передачи',
            self::StorageAct => 'Акт хранения',
            self::Agreement => 'Соглашение',
            self::TwoCopies => 'В двух экземплярах',
            self::ColorScan => 'Цветной скан',
            self::SignedScan => 'Скан подписанного',
            self::Photos => 'Фото ТС',
        };
    }
}
