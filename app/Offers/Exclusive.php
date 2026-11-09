<?php

namespace App\Offers;

use App\Users\Role;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Эксклюзив за оценку (09.11.2026, владелец: Андрей отдал Роману на оценку грузовики с «только ему 5 дней», вписал по Daf
 * XF 2611093833 свою цену 1 630 000 вместо оценки 1 500 000 и выставил «Всем сразу» — 33 менеджера, Армен выложил PDF в
 * группу; «механизм должен работать всегда, даже когда админ вписал другую цену»). Положен тому, кто оценил по просьбе с
 * `exclusive_days`: неважно, взяли его оценку (`FirstLook`) или админ вписал цену сам, выставили по одной, пачкой или слотом.
 *
 * Выход в продажу из черновика (`ChangeOfferState`) ставит в «Кому показывать» оценщика «сразу», а все остальные строки —
 * не раньше, чем через N дней от выхода («Не показывать» остаётся); подтвердил он — остальным не открывается, пока по нему
 * не решили (`SyncViewers::held`). Дан один раз — `offer_estimates.exclusive_until` (до когда; `FirstLook` кончил раньше — сейчас); вернулась машина потом в продажу — волны
 * свои. Пока идёт, правка «Показа» (`UpdateOffer`) и «Срочно» (`SyncViewers`) его не снимают.
 */
final class Exclusive
{
    /** Оценки, за которые эксклюзив положен и ещё не дан. */
    public static function owed(Offer $offer): Collection
    {
        return $offer->estimates()->with('user')
            ->where('exclusive_days', '>', 0)->whereNotNull('estimated_at')->whereNull('exclusive_until')
            ->whereIn('state', [EstimateState::Given, EstimateState::Taken, EstimateState::Closed])
            ->get()->filter(fn (Estimate $e) => $e->user?->hasRole(Role::Manager))->values();
    }

    /** Данные и ещё не кончившиеся. */
    public static function running(Offer $offer): Collection
    {
        return $offer->estimates()->with('user')->where('exclusive_until', '>', now())->get();
    }

    /** До когда эксклюзив у машины, выходящей сейчас: «Взять» с эксклюзивом (`FirstLook`) — его срок, иначе N дней от выхода. */
    public static function until(Offer $offer, Estimate $e): CarbonInterface
    {
        return $offer->first_look_user_id === $e->user_id && $offer->first_look_until
            ? $offer->first_look_until : ($offer->published_at ?? now())->copy()->addDays($e->exclusive_days);
    }

    /**
     * Правила показа с эксклюзивом: оценщики — сразу, остальные строки — не раньше конца эксклюзива (минут от выхода).
     *
     * @param  Collection<int, Estimate>  $estimates
     */
    public static function rules(array $rules, Collection $estimates, Offer $offer): array
    {
        $from = $offer->published_at ?? now();
        $users = $estimates->pluck('user_id')->unique()->all();
        // Ещё не дан (черновик, выход сейчас) — N дней от выхода; идёт — до его конца.
        $wait = (int) ceil($estimates->map(fn (Estimate $e) => $e->exclusive_until ? $from->diffInMinutes($e->exclusive_until) : $e->exclusive_days * AudienceRules::DAY)->max());
        $out = array_map(fn ($id) => ['type' => 'user', 'id' => (int) $id, 'delay' => 0], $users);
        foreach (AudienceRules::normalize($rules) as $r) {
            if ($r['type'] === 'user' && in_array($r['id'], $users, true)) {
                continue;
            }
            $out[] = ['delay' => $r['delay'] === null ? null : max($r['delay'], $wait)] + $r;
        }

        return AudienceRules::normalize($out);
    }

    /** «Роман Корабельников — 5 дней только ему за оценку». */
    public static function words(Collection $estimates): string
    {
        return $estimates->map(fn (Estimate $e) => $e->user?->shortName())->filter()->unique()->implode(', ')
            .' — '.Estimate::daysWord((int) $estimates->max('exclusive_days')).' только '.($estimates->pluck('user_id')->unique()->count() > 1 ? 'им' : 'ему').' за оценку';
    }
}
