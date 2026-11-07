<?php

namespace App\Cars\Sources;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\CarFields;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Identity;
use App\Cars\Settlement;
use App\Cars\Transmission;
use App\Cars\Vin\Vin;
use App\Cars\Vin\VinText;
use App\Mail\Extraction\Patterns;
use App\Support\Money;
use App\Support\Units;

/**
 * Значение поля машины в одном виде для всех источников (07.11.2026): что записать (`write` — колонки машины), как
 * сравнить (`key`) и как показать (`label`). Раньше у Мигторга, документов парковки и «✨» были свои чистки, и одно и то же
 * значение выглядело разницей («A123BC77» латиницей и «А123ВС77»), а разница — не записывалась («Взять» → «обновите»).
 * Здесь значение переводится в запись сразу: чего записать нельзя (модели нет у марки, города нет в справочнике, VIN у
 * другой машины, вне границ) — того и нет. Сравнивается текущее значение машины тем же ключом (`current`).
 */
final class Values
{
    /** Поля, по которым источники спорят, — подпись и колонки машины, которые поле пишет. */
    public const FIELDS = [
        'car' => ['Марка, модель', ['brand_id', 'model_id']],
        'vin' => ['VIN', ['vin']],
        'year' => ['Год', ['year']],
        'color' => ['Цвет', ['color']],
        'mileage' => ['Пробег', ['mileage']],
        'plate' => ['Госномер', ['plate']],
        'pts' => ['ПТС', ['pts']],
        'sts' => ['СТС', ['sts']],
        'body' => ['Кузов', ['body']],
        'transmission' => ['Коробка', ['transmission']],
        'drive' => ['Привод', ['drive']],
        'fuel' => ['Топливо', ['fuel']],
        'engine_volume' => ['Объём', ['engine_volume']],
        'engine_power' => ['Мощность', ['engine_power']],
        'city' => ['Город', ['settlement_id']],
        'value' => ['Оценочная', ['value']],
    ];

    /** Латиница, похожая на кириллицу госномера: «A123BC77» и «А123ВС77» — один номер. */
    private const PLATE = ['A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н', 'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х'];

    /**
     * Поля источника (вид `FillCar`: поле → ['value' => …, 'region' => …]) — в значения по полям `FIELDS`.
     * Модель, которой нет в справочнике, — вариант с её именем (`model_name`), заводится при записи; `$newModels` false —
     * только знакомая (письмо: модель там из темы, «Higer Кемеровская Область Г. Кисилевск»).
     * `$trusted` — источник со своим справочником (Мигторг): VIN не сверяется с контрольной цифрой.
     *
     * @return array<string, array{write: array<string, mixed>, key: string, label: string}>
     */
    public static function of(array $fields, ?Car $car = null, bool $trusted = false, bool $newModels = true): array
    {
        $v = fn (string $f) => isset($fields[$f]['value']) && $fields[$f]['value'] !== '' ? $fields[$f]['value'] : null;
        $out = [];
        if ($v('brand') && ($row = self::car((string) $v('brand'), $v('model') !== null ? (string) $v('model') : null, $newModels))) {
            $out['car'] = $row;
        }
        foreach (array_keys(self::FIELDS) as $field) {
            if ($field === 'car' || $v($field) === null) {
                continue;
            }
            $write = self::write($field, $v($field), $fields[$field]['region'] ?? null, $car, $trusted);
            if ($write !== null) {
                $out[$field] = self::row($field, $write);
            }
        }

        return $out;
    }

    /** Текущее значение тем же видом — у дорожки (город — колонка продажи, остальное — машины); пусто — null. */
    public static function current(object $of, string $field): ?array
    {
        $columns = self::FIELDS[$field][1];
        $write = [];
        foreach ($columns as $c) {
            $value = $of->{$c};
            $write[$c] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        if (blank($write[$columns[0]])) {
            return null;
        }

        return self::row($field, $write);
    }

    /** Одно ли это значение: марка без модели совпадает с той же маркой с любой моделью. */
    public static function same(string $field, string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($field === 'car') {
            [$ab, $am] = explode(':', $a, 2) + [1 => null];
            [$bb, $bm] = explode(':', $b, 2) + [1 => null];
            if ($ab !== $bb) {
                return false;
            }
            if ($am === null || $bm === null) {
                return true;
            }
            // Модель по имени («n…» — новая, «m…» — справочника): одно, если слова одной — начало другой без слов марки
            // («Granta» и «Lada Granta», «Largus» и «Largus универсал», «X5» и «X5 xDrive 30Li» — одна машина; «Tiggo 7» и
            // «Tiggo 7L» — разные). В справочнике бывают дубли, спором они не становятся.
            $brand = Brand::find((int) substr($ab, 1));
            $skip = array_filter(preg_split('/[^\p{L}\d]+/u', mb_strtolower(($brand?->name ?? '').' '.($brand?->name_ru ?? ''))) ?: []);
            $words = function (string $m) use ($skip) {
                $name = str_starts_with($m, 'n') ? substr($m, 1) : (string) CarModel::find((int) substr($m, 1))?->name;

                return array_values(array_diff(array_filter(preg_split('/[^\p{L}\d]+/u', mb_strtolower($name)) ?: []), $skip));
            };
            [$x, $y] = [$words($am), $words($bm)];
            if (! $x || ! $y) {
                return false;
            }
            [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];

            return array_slice($long, 0, count($short)) === $short;
        }

        return false;
    }

    /** @return array{write: array<string, mixed>, key: string, label: string} */
    public static function row(string $field, array $write): array
    {
        return ['write' => $write, 'key' => self::key($field, $write), 'label' => self::label($field, $write)];
    }

    public static function label(string $field, array $write): string
    {
        $value = $write[self::FIELDS[$field][1][0]] ?? null;

        return match ($field) {
            'car' => trim((Brand::find($write['brand_id'])?->name ?? '').' '.(($write['model_id'] ?? null) ? CarModel::find($write['model_id'])?->name : ($write['model_name'] ?? ''))),
            'mileage' => Units::km($value) ?? '',
            'engine_volume' => Units::liters($value) ?? '',
            'engine_power' => Units::hp($value) ?? '',
            'transmission' => Transmission::tryFrom((string) $value)?->label() ?? '',
            'drive' => Drive::tryFrom((string) $value)?->label() ?? '',
            'fuel' => Fuel::tryFrom((string) $value)?->label() ?? '',
            'body' => Body::tryFrom((string) $value)?->label() ?? '',
            'city' => ($s = Settlement::find($value)) ? $s->title() : '',
            'value' => Money::rub((int) $value),
            default => (string) $value,
        };
    }

    private static function key(string $field, array $write): string
    {
        $value = $write[self::FIELDS[$field][1][0]] ?? null;

        return match ($field) {
            'car' => 'b'.$write['brand_id'].(! empty($write['model_id']) ? ':m'.$write['model_id'] : (! empty($write['model_name']) ? ':n'.$write['model_name'] : '')),
            'plate' => strtr(mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $value)), self::PLATE),
            // Объём — до десятой литра: 1598 и 1600 см³ — оба «1,6 л».
            'engine_volume' => (string) round((int) $value / 100),
            'pts', 'sts', 'vin' => mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', (string) $value)),
            'color' => mb_strtolower(trim((string) $value)),
            default => (string) $value,
        };
    }

    /** Марка и модель источника → id справочника; модель источник назвал, а её нет — не вариант (кроме Мигторга). */
    private static function car(string $brand, ?string $model, bool $newModels): ?array
    {
        $b = Brand::known($brand);
        if (! $b) {
            return null;
        }
        $model = $model !== null ? trim((string) preg_replace('/\s+/u', ' ', $model)) : '';
        if ($model === '') {
            return self::row('car', ['brand_id' => $b->id, 'model_id' => null]);
        }
        // Модели нет в справочнике — вариант всё равно есть (справочник неполный, владелец 07.11.2026): модель заводится,
        // когда вариант выбрали или он встал в пустое (`Pick::write`), а не при чтении — мусор OCR, который никто не выбрал,
        // справочник не засоряет.
        $m = CarModel::known($b, $model);
        if (! $m && ! $newModels) {
            return null;
        }

        return self::row('car', $m ? ['brand_id' => $b->id, 'model_id' => $m->id] : ['brand_id' => $b->id, 'model_id' => null, 'model_name' => $model]);
    }

    /** Значение одного поля → колонки машины; записать нельзя — null. */
    private static function write(string $field, mixed $value, ?string $region, ?Car $car, bool $trusted): ?array
    {
        $int = fn (int $min, int $max) => is_numeric(preg_replace('/\s+/u', '', (string) $value)) && ($n = (int) preg_replace('/\s+/u', '', (string) $value)) >= $min && $n <= $max ? $n : null;
        $put = fn (?string $column, mixed $v) => $v === null || $v === '' ? null : [$column => $v];

        return match ($field) {
            'vin' => ($vin = Vin::full((string) $value)) && ($trusted || VinText::plausible($vin)) && ! Identity::carByVin($vin, $car?->id) ? ['vin' => $vin] : null,
            'year' => $put('year', $int(1950, now()->year + 1)),
            'mileage' => $put('mileage', $int(1, 5_000_000)),
            // «1.6» и «1,6» — литры, «1598» — см³.
            'engine_volume' => $put('engine_volume', ($l = (float) str_replace(',', '.', (string) $value)) > 0 && $l < 30 ? (int) round($l * 1000) : $int(50, 20_000)),
            'engine_power' => $put('engine_power', $int(1, 3000)),
            'value' => $put('value', $int(10_000, 100_000_000)),
            // Цвет — словарём; не узнали (обрывок OCR) — не вариант.
            'color' => $put('color', Colors::normalize((string) $value)),
            'plate' => ($p = Patterns::plateKey((string) $value)) && mb_strlen($p) <= 12 && preg_match('/\d/', $p) ? ['plate' => $p] : null,
            'pts', 'sts' => ($c = trim((string) $value)) !== '' && mb_strlen($c) <= 40 ? [$field => $c] : null,
            'transmission' => $put('transmission', Transmission::tryFrom((string) $value)?->value),
            'drive' => $put('drive', Drive::tryFrom((string) $value)?->value),
            'fuel' => $put('fuel', Fuel::tryFrom((string) $value)?->value),
            'body' => $put('body', Body::tryFrom((string) $value)?->value),
            'city' => $put('settlement_id', Settlement::resolve((string) $value, $region)['id'] ?? null),
            default => null,
        };
    }

    /** Колонка машины поля в форме дорожки (`brand_id`, у продажи тип и т. п.). */
    public static function columns(string $field): array
    {
        return self::FIELDS[$field][1];
    }

    /** Подпись поля: у марки с моделью — «Марка, модель», у остальных — как в блоках машины. */
    public static function title(string $field): string
    {
        return isset(CarFields::FIELDS[$field]) && $field !== 'pts' ? CarFields::FIELDS[$field][0] : self::FIELDS[$field][0];
    }
}
