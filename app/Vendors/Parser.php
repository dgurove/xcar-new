<?php

namespace App\Vendors;

use App\Cars\HasLabels;
use App\Mail\Extraction\Templates;

/** Каким шаблоном разбирать письма вендора. Классы шаблонов — прежние, выбор переехал с константы на карточку. */
enum Parser: string
{
    use HasLabels;

    case Generic = 'generic';
    case Tinkoff = 'tinkoff';
    case Sovcombank = 'sovcombank';
    case Energogarant = 'energogarant';
    case Insight = 'insight';

    public function label(): string
    {
        return match ($this) {
            self::Generic => 'Общий: всё из темы и тела',
            self::Tinkoff => 'Как у Т-Страхования: ТС в теме, «Цена за Лот»',
            self::Sovcombank => 'Как у Совкомбанка: блок «МАРКА МОДЕЛЬ / ГОД / КПП»',
            self::Energogarant => 'Как у Энергогаранта: «код, ТС марка / VIN» в теме',
            self::Insight => 'Как у ИНСАЙТ: «Максимальное предложение», фото ссылкой',
        };
    }

    /** @return class-string<Templates\Template> */
    public function templateClass(): string
    {
        return match ($this) {
            self::Generic => Templates\Generic::class,
            self::Tinkoff => Templates\Tinkoff::class,
            self::Sovcombank => Templates\Sovcombank::class,
            self::Energogarant => Templates\Energogarant::class,
            self::Insight => Templates\Insight::class,
        };
    }
}
