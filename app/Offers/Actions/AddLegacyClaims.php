<?php

namespace App\Offers\Actions;

use App\Mail\Extraction\Code;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Вставили список номеров убытка во вкладку «Из таблицы Кати»: черновик — в список (уходит из рабочих вкладок), номера
 * нет в системе — в список призрачной строкой, архивное — тоже (заведут — поднимется в черновик). Уже в сделке или
 * опубликованное в список не идёт: оно живёт своим путём, итог называет его.
 */
class AddLegacyClaims
{
    public function __invoke(string $text, ?User $by): LegacyResult
    {
        $result = new LegacyResult;
        $refs = [];
        // Номер на строке, через пробел, запятую или «;»: шаблоны CodeMatcher ловят не все (5025/2026, 1152451/2026).
        // Меньше шести цифр — не номер убытка, а дата или мусор из таблицы.
        foreach (preg_split('/[\s,;]+/u', $text) as $piece) {
            $ref = Code::normalize($piece);
            $key = Code::key($piece);
            if ($key && preg_match_all('/\d/', $key) >= 6 && ! isset($refs[$key])) {
                $refs[$key] = $ref;
            }
        }
        if (! $refs) {
            return $result;
        }

        DB::transaction(function () use ($refs, $by, $result) {
            $listed = LegacyClaim::whereIn('claim_ref_key', array_keys($refs))->pluck('claim_ref_key')->flip();
            $offers = Offer::with(['brand', 'model', 'deal.buyer'])->where('is_demo', false)->whereIn('claim_ref_key', array_keys($refs))->get()->keyBy('claim_ref_key');
            $position = (int) LegacyClaim::max('position');
            foreach ($refs as $key => $ref) {
                $offer = $offers->get($key);
                if (isset($listed[$key])) {
                    $result->already++;

                    continue;
                }
                $state = $offer?->state;
                if (in_array($state, [OfferState::Sold, OfferState::Garage, OfferState::Delivered], true)) {
                    $result->deals[] = $offer;

                    continue;
                }
                if ($state === OfferState::Open) {
                    $result->published[] = $offer;

                    continue;
                }
                LegacyClaim::create(['claim_ref' => $ref, 'position' => ++$position, 'added_by' => $by?->id]);
                in_array($state, [OfferState::Draft, OfferState::Gallery], true) ? $result->moved++ : $result->ghosts++;
            }
        });

        return $result;
    }
}
