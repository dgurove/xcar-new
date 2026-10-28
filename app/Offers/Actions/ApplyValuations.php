<?php

namespace App\Offers\Actions;

use App\Cars\Identity;
use App\Cars\IdentityTaken;
use App\Cars\Settlement;
use App\Cars\Vin\Vin;
use App\Offers\Intake\DraftSource;
use App\Offers\Intake\OpenDraft;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Offer;
use App\Offers\Pricing;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Оценки из текста, фото или таблицы — записать (28.10.2026: одна дверь окна «Оценить → Из текста» и бота Telegram, куда
 * админ кидает скрин СМС Марины). `prepare` — что из разбора (`MatchValuations`) можно записать: суммы, VIN и город из
 * текста, что спорит с вписанным, кого заводить; его кладут в кэш, чтобы записалось то, что человек видел, а не то, что
 * прислал браузер. `__invoke` — записать выбранное: оценочная и закупочная (`Pricing::fromAmount`), VIN в пустой, город
 * поверх; подтвердившие уже есть — закупочная уходит на переоценку (`UpdateOffer`); по номерам, которых в CRM нет, —
 * черновики (`OpenDraft`).
 */
final class ApplyValuations
{
    public function __construct(private UpdateOffer $update) {}

    /** @return array<string, mixed> */
    public static function prepare(User $user, array $items): array
    {
        $take = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'check'], true));
        $found = collect($items)->filter(fn ($i) => $i['offer'] && in_array($i['status'], ['fill', 'conflict', 'same'], true));

        return [
            'user' => $user->id,
            // VIN и город из текста — по предложению, и у тех, где сумма та же.
            'vin' => $found->filter(fn ($i) => $i['vin_fill'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['vin_fill']])->all(),
            'city' => $found->filter(fn ($i) => $i['city_fill'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['city_fill']['id']])->all(),
            // Строки с галкой: снятая — ничего не пишем.
            'checkable' => $found->filter(fn ($i) => $i['status'] === 'fill')->map(fn ($i) => $i['offer']->id)->values()->all(),
            // Кому можно вписать руками: чего нет ни у предложения, ни в тексте.
            'gaps' => $found->filter(fn ($i) => $i['gaps'])->mapWithKeys(fn ($i) => [$i['offer']->id => $i['gaps']])->all(),
            'amounts' => $take->mapWithKeys(fn ($i) => [$i['offer']->id => $i['amount']])->all(),
            // Что вписано иначе: без «Перезаписать» это поле остаётся прежним.
            'differs' => $take->filter(fn ($i) => $i['status'] === 'conflict')->mapWithKeys(fn ($i) => [$i['offer']->id => ['value' => $i['value_differs'], 'floor' => $i['floor_differs']]])->all(),
            // Нет в CRM: что заводить по галке — номер, сумма и лот Мигторга, если он есть.
            'create' => collect($items)->where('status', 'absent')->mapWithKeys(fn ($i) => [$i['key'] => [
                'ref' => $i['ref'], 'amount' => $i['amount'], 'lot' => $i['lot']?->id,
            ]])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $saved  `prepare`
     * @param  list<int>  $ids  предложения с галкой
     * @param  array<int, string>  $keep  id → keep | overwrite (где вписано иначе)
     * @param  list<string>  $create  ключи номеров, по которым завести черновик
     * @return array{done: int, repriced: int, created: list<array<string, mixed>>, vin: int, city: int, vin_taken: int}
     */
    public function __invoke(User $user, array $saved, array $ids, array $keep = [], array $create = [], array $vin = [], array $city = []): array
    {
        $ids = array_values(array_intersect($ids, array_keys($saved['amounts'])));
        // Вписанное руками — только там, где его не было ни у предложения, ни в тексте (`gaps`), и только годное.
        $typedVin = collect($vin)->map(fn ($v) => Vin::full((string) $v))
            ->filter(fn ($v, $id) => $v && in_array('vin', $saved['gaps'][$id] ?? [], true));
        $typedCity = collect($city)->filter(fn ($v, $id) => $v && in_array('city', $saved['gaps'][$id] ?? [], true) && Settlement::whereKey($v)->exists());
        $vins = ($saved['vin'] ?? []) + $typedVin->all();
        $cities = ($saved['city'] ?? []) + $typedCity->map(fn ($v) => (int) $v)->all();
        // Строку с галкой сняли — с неё не пишется ничего, и VIN с городом тоже.
        $off = array_diff($saved['checkable'] ?? [], $ids);
        $vins = array_diff_key($vins, array_flip($off));
        $cities = array_diff_key($cities, array_flip($off));
        $touch = array_values(array_unique([...$ids, ...array_keys($vins), ...array_keys($cities)]));
        $done = $vinDone = $cityDone = $vinTaken = $repriced = 0;
        foreach (Offer::query()->inCrm($user)->whereKey($touch)->get() as $offer) {
            $set = [];
            if (in_array($offer->id, $ids, true)) {
                $value = (int) $saved['amounts'][$offer->id];
                $set = Pricing::fromAmount($offer->vendor_id, $value);
                // Расхождение без «Перезаписать» — вписанное остаётся; оставили оценочную — закупочная тоже прежняя.
                $differs = $saved['differs'][$offer->id] ?? null;
                if ($differs && ($keep[$offer->id] ?? 'keep') === 'keep') {
                    $set = $differs['value'] ? [] : array_diff_key($set, $differs['floor'] ? ['floor_price' => 1] : []);
                }
                // «Уже так» по сумме, а дописывается VIN или город — оценкой не считается.
                (isset($set['value']) && (int) $offer->value !== $set['value']) || (isset($set['floor_price']) && (int) $offer->floor_price !== $set['floor_price']) ? $done++ : null;
            }
            // VIN — только в пустой (другой — перепроверить); город из текста главнее (Мигторг бывает устаревшим).
            // VIN другого живого предложения не встанет (`Identity`) — строка без него, в итоге «VIN занят».
            if (isset($vins[$offer->id]) && blank($offer->vin)) {
                if (Identity::offerByVin($vins[$offer->id], $offer->id)) {
                    $vinTaken++;
                } else {
                    $set['vin'] = $vins[$offer->id];
                    $vinDone++;
                }
            }
            if (isset($cities[$offer->id]) && $cities[$offer->id] !== $offer->settlement_id) {
                $set['settlement_id'] = $cities[$offer->id];
                $cityDone++;
            }
            if ($set === []) {
                continue;
            }
            ($this->update)($offer, $set, $user, ['source' => 'valuation', 'value' => $set['value'] ?? null, 'floor' => $set['floor_price'] ?? null]);
            // Подтвердившие уже есть — закупочная не встала, а ждёт решения переоценкой (`UpdateOffer`, 28.10.2026): это не
            // «оценено», а «на переоценку».
            if (isset($set['floor_price']) && (int) $offer->floor_price !== (int) $set['floor_price']) {
                $repriced++;
                $done = max(0, $done - 1);
            }
        }
        $created = $this->createAbsent($user, array_intersect_key($saved['create'] ?? [], array_flip($create)));

        return ['done' => $done, 'repriced' => $repriced, 'created' => $created, 'vin' => $vinDone, 'city' => $cityDone, 'vin_taken' => $vinTaken];
    }

    /**
     * Черновики по номерам, которых в CRM нет (26.10.2026): одна дверь `OpenDraft` (замок на номер, вендор по виду номера,
     * цены из суммы — `Pricing::fromAmount`, архивное и отменённое — снова черновик). Лот на Мигторге есть — поля и кадры с
     * него (`ImportMigtorgLot`), нет — черновик по номеру, лот возьмёт `migtorg:sync`, когда появится. Черновик успели
     * завести, пока окно было открыто (с Мигторга сам, другой человек), — цены из текста ложатся в него.
     *
     * @param  array<string, array{ref: string, amount: int, lot: ?int}>  $rows
     * @return list<array{number: int, title: string, ref: string, floor: int, city: ?string}>
     */
    private function createAbsent(User $user, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $lot = $row['lot'] ? DB::table('migtorg_lots')->find($row['lot']) : null;
            try {
                [$offer, $outcome] = app(OpenDraft::class)(new DraftSource($lot ? 'migtorg' : 'valuation', $row['ref'], amount: (int) $row['amount'],
                    created: $lot ? ['migtorg' => $lot->id] : ['valuation' => true], why: 'Оценка из текста'), $user);
            } catch (IdentityTaken) {
                continue;
            }
            // Вернулось из архива или уже было — цены из текста поверх прежних.
            if ($outcome !== OpenDraft::CREATED) {
                ($this->update)($offer, Pricing::fromAmount($offer->vendor_id, (int) $row['amount']), $user, ['source' => 'valuation']);
            }
            if ($lot && $outcome !== OpenDraft::EXISTING) {
                ImportMigtorgLot::start($offer->refresh(), $lot);
            }
            $offer->refresh();
            $out[] = ['number' => $offer->number, 'title' => $offer->titleWithYear() ?: $row['ref'], 'ref' => $row['ref'], 'floor' => (int) $offer->floor_price, 'city' => $offer->settlement?->title() ?? $lot?->city];
        }

        return $out;
    }
}
