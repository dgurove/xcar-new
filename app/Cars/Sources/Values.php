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

    /** Кириллица, похожая на латиницу, в имени модели строчными: «С 180» и «C-Класс» — одна «c». */
    private const LATIN = ['а' => 'a', 'в' => 'b', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x'];

    /** Цвета одним словом: «Серебряный» и «Серебристый» — один; хвост «металлик» спора не делает. */
    private const COLOR_WORDS = ['серебряный' => 'серебристый', 'серебро' => 'серебристый', 'серебр' => 'серебристый', 'графит' => 'графитовый', 'металлик' => '', 'перламутр' => '', 'матовый' => ''];

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

    /**
     * Одно ли это значение (08.11.2026, на проде 114 машин со спорами, треть — шум распознавания): та же модель другими
     * словами, VIN или ПТС с ошибкой OCR в знаке, «Серебряный» и «Серебристый», 3 713 и 3 700 км. Такое — не спор: вариант
     * склеивается с текущим значением (или с другим вариантом), а не встаёт на выбор.
     */
    public static function same(string $field, string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return match ($field) {
            'car' => self::sameCar($a, $b),
            // VIN из скана: одна-две буквы OCR («LS4…» и «LB4…»); VIN другой машины расходится больше.
            'vin' => strlen($a) === 17 && strlen($b) === 17 && levenshtein($a, $b) <= 2,
            // Серия и номер ПТС, СТС — знак OCR («16 OP» и «16 OR»); другой документ расходится больше.
            'pts', 'sts' => mb_strlen($a) === mb_strlen($b) && self::differs($a, $b) <= 1,
            'color' => self::within(explode(' ', $a), explode(' ', $b)),
            // Пробег — округление в документе (3 713 и 3 700): до 1 % или 100 км.
            'mileage' => is_numeric($a) && is_numeric($b) && abs((int) $a - (int) $b) <= max(100, 0.01 * max((int) $a, (int) $b)),
            // Объём — десятые литра, соседние — одно (7,7 и 7,8 л: 7 698 и 7 750 см³).
            'engine_volume' => is_numeric($a) && is_numeric($b) && abs((int) $a - (int) $b) <= 1,
            default => false,
        };
    }

    /**
     * Марка без модели совпадает с той же маркой с любой моделью. Модель по имени («n…» — новая, «m…» — справочника) — одна,
     * если слова одной есть в другой без слов марки и «класс» («Granta» и «Lada 2190 Granta», «UAZ PROFI» и «Profi»,
     * «Tank 300» и «Tank GWM 300», «X5» и «X5 xDrive 30Li»), или слитно одна — начало другой с хвостом из цифр
     * («Аф77 N2 Lj» и «АФ77N2LJ-02»). «Tiggo 7» и «Tiggo 7L», «C5» и «S5» — разные. Дубли справочника спором не становятся.
     */
    private static function sameCar(string $a, string $b): bool
    {
        [$ab, $am] = explode(':', $a, 2) + [1 => null];
        [$bb, $bm] = explode(':', $b, 2) + [1 => null];
        if ($ab !== $bb) {
            return false;
        }
        if ($am === null || $bm === null) {
            return true;
        }
        $brand = Brand::find((int) substr($ab, 1));
        $split = fn (string $t) => array_values(array_filter(preg_split('/[^\p{L}\d]+/u', strtr(mb_strtolower($t), self::LATIN)) ?: [], fn ($w) => $w !== ''));
        $skip = $split(($brand?->name ?? '').' '.($brand?->name_ru ?? '').' '.($brand?->slug ?? '').' класс class серия series');
        $words = function (string $m) use ($split, $skip) {
            $name = str_starts_with($m, 'n') ? substr($m, 1) : (string) CarModel::find((int) substr($m, 1))?->name;

            return array_values(array_diff($split($name), $skip));
        };
        [$x, $y] = [$words($am), $words($bm)];
        if (! $x || ! $y) {
            return false;
        }
        if (self::within($x, $y)) {
            return true;
        }
        [$short, $long] = mb_strlen(implode('', $x)) <= mb_strlen(implode('', $y)) ? [implode('', $x), implode('', $y)] : [implode('', $y), implode('', $x)];

        return mb_strlen($short) >= 6 && str_starts_with($long, $short) && ctype_digit(mb_substr($long, mb_strlen($short)));
    }

    /** Все слова одного — среди слов другого. */
    private static function within(array $x, array $y): bool
    {
        $x = array_filter($x);
        $y = array_filter($y);

        return $x && $y && (! array_diff($x, $y) || ! array_diff($y, $x));
    }

    /** Сколько знаков на тех же местах разные. */
    private static function differs(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);

        return count(array_filter(array_keys($x), fn ($i) => $x[$i] !== ($y[$i] ?? null)));
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
            // ПТС и СТС — латиница как кириллица: «78 YX» и «78 УХ» — одна серия.
            'pts', 'sts' => strtr(mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', (string) $value)), self::PLATE),
            'vin' => mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', (string) $value)),
            'color' => self::colorKey((string) $value),
            default => (string) $value,
        };
    }

    /** Цвет словами по порядку без хвостов и с одним именем серебристого: «серый», «черный серый». */
    private static function colorKey(string $value): string
    {
        $words = preg_split('/[\s,]+/u', str_replace('ё', 'е', mb_strtolower(trim($value))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter(array_map(fn ($w) => self::COLOR_WORDS[$w] ?? $w, $words), fn ($w) => $w !== '');
        sort($words);

        return implode(' ', array_unique($words)) ?: mb_strtolower(trim($value));
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

    /**
     * Все значения источников машины по полям — и отклонённые (08.11.2026, владелец: «выбрали — остальные не стирать, чтобы
     * при правке поля можно было выбрать другой, если нажал не то»): подсказки в полях правки (`x-car.source-values`).
     * Одинаковые склеены (`same`), у каждого — что записать, подпись и откуда. Один запрос на машину за запрос (`once`).
     *
     * @return array<string, list<array{key: string, label: string, write: array, from: string}>>
     */
    public static function known(int $carId): array
    {
        return once(function () use ($carId) {
            $out = [];
            foreach (\Illuminate\Support\Facades\DB::table('car_values')->where('car_id', $carId)->orderBy('id')->get()->groupBy('field') as $field => $rows) {
                $items = [];
                foreach ($rows as $row) {
                    $at = collect(array_keys($items))->first(fn ($k) => self::same($field, (string) $k, $row->key)) ?? $row->key;
                    $items[$at] ??= ['key' => $row->key, 'label' => $row->label, 'write' => json_decode($row->write, true), 'titles' => []];
                    $items[$at]['titles'][] = $row->title;
                }
                $items = array_map(fn ($i) => $i + ['from' => self::from($i['titles'])], array_values($items));
                $out[$field] = $field === 'city' ? self::places($items) : $items;
            }

            return $out;
        });
    }

    /** Откуда коротко, для узких мест: «Мигторг 815720, оценка ГОТС». */
    public static function from(array $titles): string
    {
        return str_replace('Мигторг, лот ', 'Мигторг ', implode(', ', array_unique($titles)));
    }

    /**
     * Одноимённые города — с регионом словами (08.11.2026, владелец о «Донецк, 61» и «Донецк, 80»: «лол не понял» — код
     * региона ничего не говорит): у подписей, чьи названия совпадают, — «Донецк · Рост. обл.» и «Донецк · ДНР»
     * (`regions.short`), прочие — как были. Ключ города — id места. @param list<array{key: string, label: string}> $items
     * @return list<array>
     */
    public static function places(array $items): array
    {
        $places = Settlement::with('region:id,short,name')->whereIn('id', array_map(fn ($i) => (int) $i['key'], $items))->get()->keyBy('id');
        $names = array_count_values(array_filter(array_map(fn ($i) => $places[(int) $i['key']]->name ?? null, $items)));
        foreach ($items as &$item) {
            $place = $places[(int) $item['key']] ?? null;
            if ($place && ($names[$place->name] ?? 0) > 1 && ($region = $place->region?->short ?: $place->region?->name)) {
                $item['label'] = $place->name.', '.$region;
            }
        }

        return $items;
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
