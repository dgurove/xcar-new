<?php

namespace App\Park;

use App\Cars\HasLabels;
use App\Vendors\DocRequirement;

/** Бумаги по ТС между нами и вендором: что мы должны прислать (out) и что ждём от него (in). */
enum DocKind: string
{
    use HasLabels;

    case HandoverAct = 'handover_act';
    case StorageAct = 'storage_act';
    case Photos = 'photos';
    case CommissionContract = 'commission_contract';
    case Agreement = 'agreement';
    case SaleContract = 'sale_contract';
    case Invoice = 'invoice';
    case KeysDocs = 'keys_docs';

    public function label(): string
    {
        return match ($this) {
            self::HandoverAct => 'Акт приёма-передачи',
            self::StorageAct => 'Акт хранения',
            self::Photos => 'Фото ТС',
            self::CommissionContract => 'Договор комиссии',
            self::Agreement => 'Соглашение',
            self::SaleContract => 'ДКП',
            self::Invoice => 'Счёт-фактура',
            self::KeysDocs => 'Ключи и СТС',
        };
    }

    /** Что вендор просит после приёма → какие бумаги открыть; уточнения («в двух экземплярах», «цветной скан») — в заметку. */
    public static function fromRequirement(DocRequirement $r): ?self
    {
        return match ($r) {
            DocRequirement::HandoverAct => self::HandoverAct,
            DocRequirement::StorageAct => self::StorageAct,
            DocRequirement::Agreement => self::Agreement,
            DocRequirement::Photos => self::Photos,
            default => null,
        };
    }
}
