<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Sale;
use App\Users\User;

/**
 * Пары «номер убытка — оценочная» из сообщения (`ValuationText`) против предложений CRM, которые человек правит
 * (`Offer::inCrm`: модератору — его группа). Что получится по каждой строке:
 * - `fill` — ляжет без спора: оценочной не было (или та же), закупочная пустая или посчитанная; `same` — уже так;
 * - `conflict` — в предложении вписано другое (оценочная или закупочная рукой): человек решает, перезаписать или
 *   оставить прежнее (владелец, 05.10.2026);
 * - `check` — VIN в сообщении не тот, что у предложения: без галки, решает человек;
 * - `dispute` — тот же номер дважды с разными суммами: не берётся;
 * - `missing` — предложения с таким номером нет.
 */
final class MatchValuations
{
    public const ORDER = ['conflict', 'fill', 'check', 'dispute', 'same', 'missing'];

    /**
     * @param  list<array{ref: string, key: string, amount: ?int, vin: ?string}>  $rows
     * @return list<array{status: string, ref: string, amount: ?int, vin: ?string, offer: ?Offer, floor: ?int, value_differs?: bool, floor_differs?: bool}>
     */
    public function __invoke(User $user, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => $r['amount'] !== null && $r['key'] !== ''));
        $amounts = [];
        foreach ($rows as $r) {
            $amounts[$r['key']][$r['amount']] = true;
        }
        $offers = $rows ? Offer::query()->inCrm($user)->whereIn('claim_ref_key', array_keys($amounts))
            ->whereNotIn('state', [OfferState::Archived, OfferState::Cancelled])
            ->with(['brand', 'model', 'vendor'])->get()->groupBy('claim_ref_key') : collect();

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
                $out[] = $r + ['status' => 'missing', 'offer' => null, 'floor' => null, 'value_differs' => false, 'floor_differs' => false];

                continue;
            }
            foreach ($found as $offer) {
                $vin = strtoupper((string) $offer->vin);
                $floor = Sale::floorFrom($r['amount']);
                // Вписанное расходится: оценочная другая; закупочная вписана рукой (не пустая и не посчитанная от
                // прежней оценочной) и не та, что получится.
                $valueDiffers = $offer->value !== null && (int) $offer->value !== $r['amount'];
                $floorDiffers = $offer->floor_price !== null && (int) $offer->floor_price !== $floor
                    && ! ($offer->value && (int) $offer->floor_price === Sale::floorFrom((int) $offer->value));
                $status = match (true) {
                    count($amounts[$r['key']]) > 1 => 'dispute',
                    $vin !== '' && $r['vin'] && $vin !== $r['vin'] => 'check',
                    (int) $offer->value === $r['amount'] && (int) $offer->floor_price === $floor => 'same',
                    $valueDiffers || $floorDiffers => 'conflict',
                    default => 'fill',
                };
                $out[] = $r + ['status' => $status, 'offer' => $offer, 'floor' => $floor, 'value_differs' => $valueDiffers, 'floor_differs' => $floorDiffers];
            }
        }
        usort($out, fn ($a, $b) => array_search($a['status'], self::ORDER, true) <=> array_search($b['status'], self::ORDER, true));

        return $out;
    }
}
