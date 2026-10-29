<?php

namespace App\Purchases;

use App\Cars\Category;
use App\Cars\HasLabels;
use Illuminate\Support\Str;

/**
 * Категория техники в закупке: код поставщика (ЛА, ЛКТ, ГТ, СТ) или полное название. Своей колонки у лота нет
 * (29.10.2026): тип ТС — у машины (`cars.category`), категория закупки — её группировка (`of`, `categories`) для пилюль,
 * запретов менеджерам и групп витрины.
 */
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

    /** Категория закупки по типу ТС машины: длинномер и прицеп — грузовые, мото и без типа — прочее. */
    public static function of(?Category $category): self
    {
        return match ($category) {
            Category::Passenger => self::Passenger,
            Category::Light => self::Light,
            Category::Truck, Category::Long, Category::Trailer => self::Truck,
            Category::Special => self::Special,
            default => self::Other,
        };
    }

    /** Типы ТС машины, что попадают в эту категорию (`null` — тип не указан). @return list<?string> */
    public function categories(): array
    {
        return array_values(array_map(fn (?Category $c) => $c?->value, array_filter([...Category::cases(), null], fn (?Category $c) => self::of($c) === $this)));
    }

    /** Тип ТС машины для категории из файла поставщика; «Прочее» типа не задаёт. */
    public function category(): Category
    {
        return match ($this) {
            self::Light => Category::Light,
            self::Truck => Category::Truck,
            self::Special => Category::Special,
            default => Category::Passenger,
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
