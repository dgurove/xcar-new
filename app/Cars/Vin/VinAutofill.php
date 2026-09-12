<?php

declare(strict_types=1);

namespace App\Cars\Vin;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Drive;
use App\Cars\Transmission;

/**
 * Готовит значения формы оффера по VIN.
 *
 * Марка и модель теперь справочник, а не свободный текст, поэтому декодер
 * возвращает названия, а здесь они превращаются в существующие записи. Если
 * такой марки в справочнике нет — поле остаётся пустым: заводить записи по
 * догадке декодера значило бы засорять справочник, по которому строится
 * фильтр каталога.
 *
 * Главное правило декодера действует и здесь: пустое поле честнее выдуманного.
 * Ничего не подставляем без доказательства, а ненадёжное помечаем словами.
 */
class VinAutofill
{
    public function __construct(
        private readonly VinDecoder $decoder = new VinDecoder,
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

        $brand = null;

        if (is_string($result->get('brand')) && $result->get('brand') !== '') {
            $brand = $this->findBrand((string) $result->get('brand'));

            if ($brand !== null) {
                $values['brand_id'] = $brand->id;
                $filled[] = "марка — {$brand->name}";
            } else {
                $skipped[] = 'марка — «'.$result->get('brand').'» в справочнике не нашлась';
            }
        }

        if ($brand !== null && is_string($result->get('model')) && $result->get('model') !== '') {
            $model = $this->findModel($brand, (string) $result->get('model'));

            if ($model !== null) {
                $values['model_id'] = $model->id;
                $filled[] = "модель — {$model->name}";
            } else {
                $skipped[] = 'модель — «'.$result->get('model').'» у этой марки не нашлась';
            }
        } elseif (is_string($result->get('model_candidates'))) {
            $skipped[] = 'модель — из VIN однозначно не выводится, варианты: '
                .$result->get('model_candidates');
        }

        $year = $result->get('year');

        if (is_int($year) && $year >= 1900 && $year <= (int) date('Y')) {
            $values['year'] = $year;
            // Год из VIN бывает ненадёжен, и молчать об этом нельзя: по нему
            // считают цену.
            $filled[] = "год — {$year}"
                .($result->confidence('year') === 'low' ? ' (ненадёжно, сверьте с ПТС)' : '');
        }

        $transmission = $result->get('transmission_type');

        if (is_string($transmission) && ($case = Transmission::tryFrom($transmission)) !== null) {
            $values['transmission'] = $case->value;
            $filled[] = 'КПП — '.$case->label();
        }

        $drive = $result->get('drive_type');

        if (is_string($drive) && ($case = Drive::tryFrom($drive)) !== null) {
            $values['drive'] = $case->value;
            $filled[] = 'привод — '.$case->label();
        }

        return ['result' => $result, 'values' => $values, 'filled' => $filled, 'skipped' => $skipped];
    }

    /** Ищем по slug: «Lada» из VIN и «LADA» из справочника — одна марка. */
    private function findBrand(string $name): ?Brand
    {
        return Brand::where('slug', Brand::slugFor($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    private function findModel(Brand $brand, string $name): ?CarModel
    {
        return CarModel::where('brand_id', $brand->id)
            ->where(fn ($q) => $q->where('slug', Brand::slugFor($name))->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]))
            ->first();
    }
}
