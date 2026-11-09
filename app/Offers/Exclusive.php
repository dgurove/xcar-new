<?php

namespace App\Offers;

use App\Users\Role;
use App\Users\User;
use Illuminate\Validation\ValidationException;
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
 *
 * Дыры закрыты тем же вечером (владелец: «после оценки в любом случае публикация эксклюзивная; для всех — супер редкое
 * исключение, в доп. меню и с предупреждением»): положен и тому, кто ещё не оценил (просьба висит, цену вписали сами); снялись с
 * продажи и вышли снова, пока срок идёт, — снова только ему до прежнего конца (`due`); в галерею — нельзя (`guard`); крестик
 * «Снять с оценки» и «Выставить всем…» / «Открыть всем сейчас…» — только с подтверждением (`waive`, `withdrawn_at`); «Отдать на
 * оценку ещё» его не гасит.
 */
final class Exclusive
{
    /** Просьбы, за которые эксклюзив положен и ещё не дан: было на оценке (и не оценил ещё), не отказался, админ не снял. */
    public static function owed(Offer $offer): Collection
    {
        return $offer->estimates()->with('user')
            ->where('exclusive_days', '>', 0)->whereNull('exclusive_until')->whereNull('withdrawn_at')
            ->whereIn('state', [EstimateState::Asked, EstimateState::Given, EstimateState::Taken, EstimateState::Closed])
            ->get()->filter(fn (Estimate $e) => $e->user?->hasRole(Role::Manager))->values();
    }

    /** Что ставит выход в продажу: положенные и ещё идущие (снялись и вышли снова — до прежнего конца). */
    public static function due(Offer $offer): Collection
    {
        return self::owed($offer)->concat(self::running($offer))->unique('id')->values();
    }

    /** Галерею видят все менеджеры — машине с положенным эксклюзивом туда нельзя. */
    public static function guard(Offer $offer): void
    {
        if (($due = self::due($offer))->isNotEmpty()) {
            throw ValidationException::withMessages(['state' => 'Эксклюзив за оценку — '.self::who($due).': в галерею нельзя, сначала «Выставить всем…»']);
        }
    }

    /** Снять осознанно (крестик с подтверждением, «Выставить всем…»): больше не положен и не идёт. */
    public static function waive(Offer $offer, User $by, ?Collection $only = null): Collection
    {
        $due = ($only ?? self::due($offer))->filter(fn (Estimate $e) => $e->exclusive_days > 0)->values();
        if ($due->isNotEmpty()) {
            Estimate::whereIn('id', $due->pluck('id'))->update(['withdrawn_at' => now(), 'exclusive_until' => now()]);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Эксклюзив за оценку снят: '.self::who($due).' — '.$by->shortName()]);
        }

        return $due;
    }

    /** «Роман Корабельников» — именами как есть: склонение фамилий не угадываем. */
    public static function who(Collection $estimates): string
    {
        return $estimates->map(fn (Estimate $e) => $e->user?->shortName())->filter()->unique()->implode(', ');
    }

    /** Текст предупреждения: «Обещано 5 дней только ему, оценил 1 500 000 ₽» / «ещё оценивает». */
    public static function warning(Collection $estimates): string
    {
        $e = $estimates->first();
        $what = $e->amount ? 'оценил '.\App\Support\Money::rub($e->amount) : ($e->state === EstimateState::Asked ? 'ещё оценивает' : 'был на оценке');

        return 'Обещано '.Estimate::daysWord((int) $estimates->max('exclusive_days')).' только ему, '.$what.'. Остальные менеджеры увидят машину сразу';
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
        return self::who($estimates).' — '.Estimate::daysWord((int) $estimates->max('exclusive_days')).' только '.($estimates->pluck('user_id')->unique()->count() > 1 ? 'им' : 'ему').' за оценку';
    }
}
