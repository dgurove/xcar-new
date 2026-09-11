<?php

namespace App\Purchases;

use App\Cars\HasLabels;
use Illuminate\Support\Str;

/** Категория техники: код поставщика (ЛА, ЛКТ, ГТ, СТ) или полное название. */
enum Kind: string
{
    use HasLabels;

    case Passenger = 'passenger';
    case Light = 'light';
    case Truck = 'truck';
    case Special = 'special';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Passenger => 'Легковые',
            self::Light => 'Лёгкий коммерческий',
            self::Truck => 'Грузовые',
            self::Special => 'Спецтехника',
            self::Other => 'Прочее',
        };
    }

    public static function fromSupplier(?string $code, ?string $full): self
    {
        $byCode = match (mb_strtoupper(trim((string) $code))) {
            'ЛА' => self::Passenger, 'ЛКТ' => self::Light, 'ГТ' => self::Truck, 'СТ' => self::Special, default => null,
        };
        if ($byCode) {
            return $byCode;
        }
        $text = mb_strtolower(str_replace('ё', 'е', trim((string) $full)));

        return match (true) {
            $text === '' => self::Other,
            Str::contains($text, ['погрузчик', 'спецтехника', 'крановой', 'экскаватор', 'каток']) => self::Special,
            Str::contains($text, ['легкий коммерческий', 'микроавтобус']) => self::Light,
            Str::contains($text, ['прицеп', 'тягач', 'грузов', 'автобус']) => self::Truck,
            Str::contains($text, ['легков']) => self::Passenger,
            default => self::Other,
        };
    }
}
