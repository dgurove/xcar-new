<?php

namespace App\Billing;

use App\Cars\HasLabels;

/**
 * Форма акта страховой к счёту закрытия месяца (архив «СТОЯНКА», 05.11.2026, владелец: «акт — такой же файл, как
 * раньше»): каждой страховой — её прежний файл, заполненный нашими данными (`Acts\RegistryAct`, шаблоны в
 * `resources/templates/acts`). Альфа и все без своей формы — отчёт-акт Excel; СОГАЗ — свой акт Excel; РГС — акт Word и
 * приложение Excel; Интери — акт Word. Эвакуация — у СОГАЗа своей формой, у остальных — отчёт-актом.
 */
enum ActForm: string
{
    use HasLabels;

    case Report = 'report';
    case Sogaz = 'sogaz';
    case Rgs = 'rgs';
    case Works = 'works';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Отчёт-акт Excel (Альфа)',
            self::Sogaz => 'Акт об оказании услуг Excel (СОГАЗ)',
            self::Rgs => 'Акт Word и приложение Excel (РГС)',
            self::Works => 'Акт выполненных работ Word (Интери)',
        };
    }

    /**
     * Файлы акта: шаблон → имя файла без номера.
     *
     * @return array<string, string>
     */
    public function files(bool $tow): array
    {
        if ($tow) {
            return $this === self::Sogaz ? ['sogaz-tow.xlsx' => 'Акт по эвакуации'] : ['report-tow.xlsx' => 'Отчет-акт эвакуации'];
        }

        return match ($this) {
            self::Report => ['report.xlsx' => 'Отчет-акт'],
            self::Sogaz => ['sogaz.xlsx' => 'Акт об оказании услуг'],
            self::Rgs => ['rgs.docx' => 'Акт выполненных работ', 'rgs-appendix.xlsx' => 'Приложение к акту'],
            self::Works => ['works.docx' => 'Акт выполненных работ'],
        };
    }
}
