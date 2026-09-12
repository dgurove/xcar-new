<?php

declare(strict_types=1);

namespace App\Cars\Vin;

use App\Cars\Body;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Transmission;
use Illuminate\Support\Facades\DB;

/**
 * Готовит значения формы машины по VIN.
 *
 * Два источника: таблицы декодера и память базы (`VinMemory`). Память
 * важнее — это не таблица, а машины, которые человек уже описал рядом с
 * тем же началом VIN, и она знает то, чего в таблицах нет: Haval из Тулы,
 * Москвич на КАМАЗе, китайские грузовики. Марка и модель — записи
 * справочника; чего в нём нет — не заводим, поле остаётся пустым.
 *
 * Главное правило: пустое поле честнее выдуманного. Ненадёжное помечаем словами.
 */
class VinAutofill
{
    /** Что подставляется по слову из русского названия кузова. */
    private const BODIES = [
        'Седельный' => Body::Truck, 'Грузовик' => Body::Truck, 'Шасси' => Body::Truck, 'Грузовой фургон' => Body::Van,
        'Микроавтобус' => Body::Bus, 'Автобус' => Body::Bus, 'Седан' => Body::Sedan, 'Лимузин' => Body::Sedan,
        'Хэтчбек' => Body::Hatchback, 'Универсал' => Body::Wagon, 'Внедорожник-пикап' => Body::Pickup, 'Пикап' => Body::Pickup,
        'Внедорожник' => Body::Suv, 'Кроссовер' => Body::Crossover, 'Купе' => Body::Coupe, 'Кабриолет' => Body::Coupe,
        'Родстер' => Body::Coupe, 'Минивэн' => Body::Minivan, 'Фургон' => Body::Van, 'Мотоцикл' => Body::Moto,
    ];

    public function __construct(
        private readonly VinDecoder $decoder = new VinDecoder,
        private readonly VinMemory $memory = new VinMemory,
        private readonly VinTables $tables = new VinTables,
    ) {}

    /**
     * @return array{
     *     result: VinResult,
     *     values: array<string, mixed>,
     *     filled: list<string>,
     *     skipped: list<string>
     * }
     */
    public function suggest(string $raw): array
    {
        $result = $this->decoder->decode($raw);
        $values = [];
        $filled = [];
        $skipped = [];

        if (! $result->valid) {
            return ['result' => $result, 'values' => [], 'filled' => [], 'skipped' => []];
        }

        $memory = $this->memory->recall((string) $result->vin);
        $known = $memory['values'];

        $brand = isset($known['brand_id']) ? Brand::find($known['brand_id']) : null;
        $decoded = $result->get('brand');
        if ($brand === null && is_string($decoded) && $decoded !== '') {
            $brand = $this->findBrand($decoded);
            if ($brand === null) {
                $skipped[] = 'марка — «'.$decoded.'» в справочнике не нашлась';
            }
        }
        if ($brand !== null) {
            $values['brand_id'] = $brand->id;
            $filled[] = "марка — {$brand->name}";
        }

        $model = null;
        if ($brand !== null && isset($known['model_id'])) {
            $model = CarModel::where('brand_id', $brand->id)->find($known['model_id']);
        }
        if ($brand !== null && $model === null && is_string($result->get('model')) && $result->get('model') !== '') {
            $model = $this->findModel($brand, (string) $result->get('model'));
            if ($model === null) {
                $skipped[] = 'модель — «'.$result->get('model').'» у этой марки не нашлась';
            }
        }
        if ($model !== null) {
            $values['model_id'] = $model->id;
            $filled[] = "модель — {$model->name}";
        } elseif ($memory['model_candidates'] !== []) {
            $skipped[] = 'модель — в базе под таким началом VIN разные: '.implode(', ', $memory['model_candidates']);
        } elseif (is_string($result->get('model_candidates'))) {
            $skipped[] = 'модель — из VIN однозначно не выводится, варианты: '.$result->get('model_candidates');
        }

        $year = $result->get('year');
        if ($result->confidence('year') === 'low') {
            // Renault, Mercedes, BMW год в VIN не пишут: подставить «2012»
            // Москвичу 2023 года хуже, чем оставить пусто — по году считают цену.
            $skipped[] = 'год — у этой марки в VIN не кодируется, смотрите ПТС';
        } elseif (is_int($year) && $year >= 1900 && $year <= (int) date('Y')) {
            $values['year'] = $year;
            $filled[] = "год — {$year}";
        }

        $transmission = Transmission::tryFrom((string) ($known['transmission'] ?? $result->get('transmission_type') ?? ''));
        if ($transmission !== null) {
            $values['transmission'] = $transmission->value;
            $filled[] = 'КПП — '.$transmission->label();
        }

        $drive = Drive::tryFrom((string) ($known['drive'] ?? $result->get('drive_type') ?? ''));
        if ($drive !== null) {
            $values['drive'] = $drive->value;
            $filled[] = 'привод — '.$drive->label();
        }

        $fuelCode = $known['fuel'] ?? $result->get('fuel_type');
        $fuel = Fuel::tryFrom(match ($fuelCode) {
            'cng', 'lpg' => 'gas', default => (string) $fuelCode
        });
        if ($fuel !== null) {
            $values['fuel'] = $fuel->value;
            $filled[] = 'топливо — '.$fuel->label();
        }

        $body = isset($known['body']) ? Body::tryFrom((string) $known['body']) : $this->bodyOf($result->get('body'));
        if ($body !== null) {
            $values['body'] = $body->value;
            $filled[] = 'кузов — '.$body->label();
        }

        $volume = $known['engine_volume'] ?? (is_numeric($result->get('displacement')) ? (int) round((float) $result->get('displacement') * 1000) : null);
        if ($volume) {
            $values['engine_volume'] = (int) $volume;
            $filled[] = 'объём — '.number_format((int) $volume, 0, '', ' ').' см³';
        }

        $power = $known['engine_power'] ?? $result->get('power_hp');
        if (is_numeric($power) && (int) $power > 0) {
            $values['engine_power'] = (int) $power;
            $filled[] = 'мощность — '.(int) $power.' л. с.';
        }

        return ['result' => $result, 'values' => $values, 'filled' => $filled, 'skipped' => $skipped];
    }

    /** Ищем по slug, имени и русскому имени, через алиасы: «Li Auto» из VIN и «Lixiang» из справочника — одна марка. */
    private function findBrand(string $name): ?Brand
    {
        $names = [$name];
        foreach ($this->tables->brandAliases() as $canonical => $aliases) {
            if (in_array(mb_strtolower($name), array_map('mb_strtolower', [$canonical, ...$aliases]), true)) {
                $names = array_unique([$canonical, ...$aliases, $name]);
                break;
            }
        }
        $lower = array_map('mb_strtolower', $names);
        $slugs = array_map(Brand::slugFor(...), $names);

        return Brand::whereIn('slug', $slugs)->orWhereIn(DB::raw('lower(name)'), $lower)->orWhereIn(DB::raw('lower(name_ru)'), $lower)->first();
    }

    private function findModel(Brand $brand, string $name): ?CarModel
    {
        return CarModel::where('brand_id', $brand->id)
            ->where(fn ($q) => $q->where('slug', Brand::slugFor($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))
            ->first();
    }

    private function bodyOf(mixed $text): ?Body
    {
        if (! is_string($text)) {
            return null;
        }
        foreach (self::BODIES as $word => $body) {
            if (str_contains($text, $word)) {
                return $body;
            }
        }

        return null;
    }
}
