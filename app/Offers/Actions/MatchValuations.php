<?php

namespace App\Offers\Actions;

use App\Cars\CarText;
use App\Cars\Identity;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Пары «номер убытка — оценочная» из сообщения (`ValuationText`) против предложений CRM, которые человек правит
 * (`Offer::inCrm`: модератору — его группа). Что получится по каждой строке:
 * - `fill` — ляжет без спора: оценочной не было (или та же), закупочная пустая или посчитанная; `same` — уже так;
 * - `conflict` — в предложении вписано другое (оценочная или закупочная рукой): человек решает, перезаписать или
 *   оставить прежнее (владелец, 05.10.2026);
 * - `check` — VIN в сообщении не тот, что у предложения, или номер Т-Страхования нашёлся только без года
 *   (`ref_differs`: в СМС AUT-26-1103537, в CRM AUT-25-1103537 — 26.10.2026, владелец): без галки, решает человек;
 * - `dispute` — тот же номер дважды с разными суммами: не берётся;
 * - `migtorg` — предложения нет (или оно в архиве), а лот с этим номером есть на Мигторге: из окна заводится черновик
 *   с закупочной из текста, поля и фото — с лота (`ImportMigtorgLot`; 26.10.2026, владелец: «неудобно потом по тем,
 *   которых нет, отдельно идти создавать»);
 * - `missing` — предложения с таким номером нет и на Мигторге тоже.
 *
 * Заодно VIN и город из текста (05.10.2026): Мигторг заполняет их как раньше, а текст от страховой главнее (владелец):
 * `vin_fill` — VIN только в пустой (другой VIN, хоть от Мигторга, — тревожный знак, `check`: перепроверить),
 * `city_fill` — город в пустой или вместо другого, `gaps` — чего не будет и после сохранения (нет ни у предложения, ни в тексте):
 * их вписывают тут же. Сумма та же, а дописать есть что — строка не «Уже так», а «Заполним».
 *
 * Сумма — оценочная только у вендора, чья закупочная от неё (`Pricing::fromAmount`, `floor_by_value`, Альфа). У остальных (Т-Страхование:
 * Марина присылает СМС «AUT-26-979366 207000», 22.10.2026) это сама закупочная — `direct`: оценочная не трогается.
 */
final class MatchValuations
{
    public const ORDER = ['conflict', 'fill', 'check', 'dispute', 'same', 'migtorg', 'missing'];

    /**
     * @param  list<array{ref: string, key: string, amount: ?int, vin: ?string, city?: ?array}>  $rows
     * @return list<array{status: string, ref: string, amount: ?int, vin: ?string, offer: ?Offer, floor: ?int, value_differs?: bool, floor_differs?: bool}>
     */
    public function __invoke(User $user, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => $r['amount'] !== null && $r['key'] !== ''));
        $amounts = [];
        foreach ($rows as $r) {
            $amounts[$r['key']][$r['amount']] = true;
        }
        $offers = $rows ? Offer::query()->inCrm($user)->whereCar(fn ($c) => $c->whereIn('claim_ref_key', array_keys($amounts)))
            ->whereNotIn('state', [OfferState::Archived, OfferState::Cancelled])
            ->with(['vendor', 'settlement'])->get()->groupBy('claim_ref_key') : collect();

        // Номер Т-Страхования, не найденный точно, — по цифрам с любым годом: в СМС год бывает не тот. Только когда
        // совпадение одно — иначе непонятно, какая машина.
        $loose = [];
        foreach (array_diff(array_keys($amounts), $offers->keys()->all()) as $key) {
            if (preg_match('/^aut\d{2}(\d{6,7})$/', $key, $m)) {
                $near = Offer::query()->inCrm($user)->whereCar(fn ($c) => $c->whereRaw('claim_ref_key ~ ?', ['^aut[0-9]{2}'.$m[1].'$']))
                    ->whereNotIn('state', [OfferState::Archived, OfferState::Cancelled])->with(['vendor', 'settlement'])->limit(2)->get();
                $near->count() === 1 && $loose[$key] = $near->first();
            }
        }
        foreach ($loose as $key => $offer) {
            $offers->put($key, collect([$offer]));
        }
        $absent = array_values(array_diff(array_keys($amounts), $offers->keys()->all()));
        // Лот Мигторга по номеру: живой важнее ушедшего, свежий — прежнего.
        $lots = $absent ? DB::table('migtorg_lots')->whereIn('claim_ref_key', $absent)->orderByRaw('gone_at is not null')->orderByDesc('id')
            ->get(['id', 'claim_ref_key', 'title', 'city', 'vin'])->unique('claim_ref_key')->keyBy('claim_ref_key') : collect();
        // Предложение по номеру в архиве — заводить второе нельзя (машина одна), его вернут в черновики.
        $archived = $lots->isNotEmpty() ? Offer::query()->inCrm($user)->whereCar(fn ($c) => $c->whereIn('claim_ref_key', $lots->keys()->all()))
            ->whereIn('state', [OfferState::Archived, OfferState::Cancelled])->latest('id')->get()->unique('claim_ref_key')->keyBy('claim_ref_key') : collect();

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            // Тот же номер с той же суммой — повтор строки, второй раз не нужен.
            if (isset($seen[$r['key']][$r['amount']])) {
                continue;
            }
            $seen[$r['key']][$r['amount']] = true;
            $found = $offers->get($r['key'], collect());
            if ($found->isEmpty()) {
                $lot = $lots->get($r['key']);
                // Черновик с Мигторга получит вендора по виду номера — цены из суммы по его правилу.
                $floor = $lot ? Pricing::fromAmount(CarText::vendorOf($r['ref']), $r['amount'])['floor_price'] : null;
                $out[] = $r + ['status' => $lot && count($amounts[$r['key']]) === 1 ? 'migtorg' : 'missing', 'lot' => $lot, 'archived' => $archived->get($r['key']), 'offer' => null, 'direct' => false, 'floor' => $floor, 'value_differs' => false, 'floor_differs' => false, 'vin_fill' => null, 'vin_taken' => null, 'city_fill' => null, 'gaps' => []];

                continue;
            }
            foreach ($found as $offer) {
                $vin = strtoupper((string) $offer->vin);
                $direct = ! Pricing::byValue($offer->vendor_id);
                $floor = $direct ? $r['amount'] : Pricing::floorFrom($r['amount']);
                // Вписанное расходится: оценочная другая; закупочная вписана рукой (не пустая и не посчитанная от
                // прежней оценочной) и не та, что получится.
                $valueDiffers = ! $direct && $offer->value !== null && (int) $offer->value !== $r['amount'];
                $floorDiffers = $offer->floor_price !== null && (int) $offer->floor_price !== $floor
                    && ($direct || ! ($offer->value && (int) $offer->floor_price === Pricing::floorFrom((int) $offer->value)));
                $city = $r['city'] ?? null;
                $vinFill = $vin === '' && $r['vin'] ? $r['vin'] : null;
                // VIN из текста — у другого живого предложения: это не та машина или двойник, не записываем (`Identity`).
                $vinTaken = $vinFill ? Identity::offerByVin($vinFill, $offer->id) : null;
                $vinFill = $vinTaken ? null : $vinFill;
                $cityFill = $city && $city['id'] !== $offer->settlement_id ? $city + ['from' => $offer->settlement?->title()] : null;
                $gaps = array_values(array_filter([$vin === '' && ! $r['vin'] ? 'vin' : null, ! $offer->settlement_id && ! $city ? 'city' : null]));
                $refDiffers = isset($loose[$r['key']]) ? (string) $offer->claim_ref : null;
                $status = match (true) {
                    count($amounts[$r['key']]) > 1 => 'dispute',
                    $refDiffers !== null => 'check',
                    $vinTaken || ($vin !== '' && $r['vin'] && $vin !== $r['vin']) => 'check',
                    ($direct || (int) $offer->value === $r['amount']) && (int) $offer->floor_price === $floor => $vinFill || $cityFill || $gaps ? 'fill' : 'same',
                    $valueDiffers || $floorDiffers => 'conflict',
                    default => 'fill',
                };
                $out[] = $r + ['status' => $status, 'offer' => $offer, 'direct' => $direct, 'floor' => $floor, 'value_differs' => $valueDiffers, 'floor_differs' => $floorDiffers,
                    'vin_fill' => $vinFill, 'vin_taken' => $vinTaken, 'city_fill' => $cityFill, 'gaps' => $gaps, 'ref_differs' => $refDiffers];
            }
        }
        usort($out, fn ($a, $b) => array_search($a['status'], self::ORDER, true) <=> array_search($b['status'], self::ORDER, true));

        return $out;
    }
}
