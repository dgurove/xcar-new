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
        // Оценивали несколько, эксклюзив не решён (24.11.2026): галерея показала бы машину всем, решить можно только при выставлении.
        if (($open = self::undecided($offer))->isNotEmpty()) {
            throw ValidationException::withMessages(['state' => 'Оценили '.self::who($open).', эксклюзив за оценку не решён: в галерею нельзя, выставьте в продажу с выбором']);
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

    /**
     * Оценили, а эксклюзив никто не решал (24.11.2026, Рома: Krone и Howo отдали на оценку троим, эксклюзив бывает только при
     * оценке одному — и машины ушли «Всем сразу»; владелец: «при публикации делать выбор, кому из них эксклюзив»). Оценки
     * менеджеров с суммой, не корректировка, `exclusive_days` пуст, не сняты; даны после последнего выхода — прежние при
     * возврате в продажу не спрашиваются. Пока такие есть, выставить нельзя — решают в шторке «Выставить в продажу» (`decide`).
     *
     * @return Collection<int, Estimate>
     */
    public static function undecided(Offer $offer): Collection
    {
        return self::candidates($offer)->filter(fn (Estimate $e) => $e->exclusive_days === null)->values();
    }

    /**
     * О ком решают эксклюзив до выхода (24.11.2026, владелец: «сначала в «Показе» одного, при публикации другого или никого —
     * перепроверь, что корректно перезапишет»): оценки менеджеров этого цикла — с суммой, не корректировка, не сняты, эксклюзив
     * ещё не идёт (`exclusive_until` пуст), даны после последнего выхода. Решённые (`exclusive_days` > 0 — ему, 0 — нет) тоже
     * здесь: выбор переписывается целиком (`decide`) в «Показе», шторке выставления и пачке.
     *
     * @return Collection<int, Estimate>
     */
    public static function candidates(Offer $offer): Collection
    {
        return self::cycle($offer->estimates()->getQuery(), $offer->published_at)->values();
    }

    /** Оценки цикла из запроса (одна машина или много) — правило `candidates`. */
    private static function cycle(\Illuminate\Database\Eloquent\Builder $q, mixed $publishedAt = false): Collection
    {
        return $q->with('user')->whereNull('withdrawn_at')->whereNull('exclusive_until')->whereNull('base_price')->whereNotNull('amount')
            ->whereIn('state', [EstimateState::Given, EstimateState::Taken, EstimateState::Closed])
            ->when($publishedAt !== false && $publishedAt, fn ($w) => $w->where('estimated_at', '>', $publishedAt))
            ->get()->filter(fn (Estimate $e) => $e->user?->hasRole(Role::Manager))->sortByDesc('amount');
    }

    /**
     * Решили (переписывает прежнее решение целиком): выбранным оценщикам цикла — эксклюзив на N дней (`owed` подхватит при выходе),
     * остальным — «нет» (0). «Никому» — пустой выбор. В «Историю» — только если что-то поменялось.
     */
    public static function decide(Offer $offer, array $estimateIds, int $days, User $by): Collection
    {
        $all = self::candidates($offer);
        $ids = array_map('intval', $estimateIds);
        $chosen = $all->whereIn('id', $ids)->values();
        $days = in_array($days, array_filter(Estimate::EXCLUSIVE), true) ? $days : 5;
        $want = $all->mapWithKeys(fn (Estimate $e) => [$e->id => in_array($e->id, $ids, true) ? $days : 0]);
        $changed = $all->filter(fn (Estimate $e) => $e->exclusive_days !== $want[$e->id]);
        foreach ($changed->groupBy(fn (Estimate $e) => $want[$e->id]) as $value => $group) {
            Estimate::whereIn('id', $group->pluck('id'))->update(['exclusive_days' => (int) $value]);
        }
        if ($changed->isNotEmpty()) {
            $offer->log(OfferEventType::Note, $by, ['text' => 'Эксклюзив за оценку: '.($chosen->isEmpty() ? 'никому' : self::who($chosen).', '.Estimate::daysWord($days))]);
        }

        return $chosen;
    }

    /**
     * Решение из формы (`x-offer.exclusive-pick`: `exclusive[{id}][to][]`, `[none]`, `[days]`; прежние плоские `exclusive_to[]`,
     * `exclusive_none`, `exclusive_days` — тоже). Нерешённых нет или стоит в слоте — ничего; есть, а выбора нет — ошибка
     * (`unanswered`), если `$required`, иначе ничего (шторка «Показ»: «Сохранить» без выбора оставляет решение на потом).
     */
    public static function decideFrom(\Illuminate\Http\Request $request, Offer $offer, bool $required = true): void
    {
        // Стоящей в слоте — перенос, не выход: решать в шторке «Перенести» негде (`ScheduleOffer` так же).
        if ($offer->isScheduled() || self::candidates($offer)->isEmpty()) {
            return;
        }
        $own = $request->input('exclusive.'.$offer->id);
        $field = fn (string $key, string $flat) => is_array($own) ? ($own[$key] ?? null) : $request->input($flat);
        $to = array_values(array_filter((array) $field('to', 'exclusive_to'), 'is_numeric'));
        $none = filter_var($field('none', 'exclusive_none'), FILTER_VALIDATE_BOOL);
        if ($to === [] && ! $none) {
            // Ответа нет: решённое остаётся, нерешённое — ошибка там, где без решения нельзя (выставление).
            if ($required && self::undecided($offer)->isNotEmpty()) {
                throw self::unanswered();
            }

            return;
        }
        self::decide($offer, $to, (int) ($field('days', 'exclusive_days') ?: 5), $request->user());
    }

    /**
     * Оценщики цикла по многим машинам разом (шторка пачки «Оцененных», `x-offer.publish-sheet pack`): offer_id → оценки, только
     * у кого есть. Машины — с `car`, для названия в шторке.
     *
     * @param  iterable<int>  $offerIds
     * @return Collection<int, array{offer: Offer, estimates: Collection<int, Estimate>}>
     */
    public static function candidatesMany(iterable $offerIds): Collection
    {
        $offers = Offer::whereIn('id', collect($offerIds)->all())->whereIn('state', [OfferState::Draft, OfferState::Gallery])->whereNull('slot_at')
            ->with('car')->get()->keyBy('id');
        if ($offers->isEmpty()) {
            return collect();
        }

        return self::cycle(Estimate::query()->whereIn('offer_id', $offers->keys()))
            ->filter(fn (Estimate $e) => ! ($at = $offers[$e->offer_id]->published_at) || ($e->estimated_at && $e->estimated_at->gt($at)))
            ->groupBy('offer_id')->map(fn ($group, $id) => ['offer' => $offers[$id], 'estimates' => $group->values()]);
    }

    public const ASK = 'Выберите, кому эксклюзив за оценку';

    /** Ошибка «не решили»: `exclusive` — пачке (своя строка тоста), `state` — красной плашкой окна и страницы, иначе её не видно. */
    public static function unanswered(): ValidationException
    {
        return ValidationException::withMessages(['exclusive' => self::ASK, 'state' => self::ASK]);
    }

    /**
     * Кого отметить в выборе: уже решено — кому решили (`exclusive_days` > 0); никто не решал — Рома (24.11.2026, владелец: «чаще
     * всего эксклюзив у Ромы — выбран галочкой, с возможностью убрать», `xcar.heavy_estimator`); решили «никому» — никого.
     *
     * @param  Collection<int, Estimate>  $estimates  оценщики цикла (`candidates`)
     * @return list<int> id оценок
     */
    public static function preferred(Collection $estimates): array
    {
        $chosen = $estimates->filter(fn (Estimate $e) => $e->exclusive_days > 0);
        if ($chosen->isNotEmpty()) {
            return $chosen->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
        if ($estimates->contains(fn (Estimate $e) => $e->exclusive_days !== null)) {
            return [];
        }
        $roma = (int) config('xcar.heavy_estimator');

        return $roma ? $estimates->where('user_id', $roma)->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : [];
    }

    /** Решили «никому»: оценщики есть, все — 0. */
    public static function nobody(Collection $estimates): bool
    {
        return $estimates->isNotEmpty() && $estimates->every(fn (Estimate $e) => $e->exclusive_days === 0);
    }

    /** Срок выбора: решённый или 5 дней. */
    public static function chosenDays(Collection $estimates): int
    {
        return (int) ($estimates->max('exclusive_days') ?: 5);
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
        $users = $estimates->pluck('user_id')->unique()->all();
        $wait = self::wait($estimates, $offer);
        $out = array_map(fn ($id) => ['type' => 'user', 'id' => (int) $id, 'delay' => 0], $users);
        foreach (AudienceRules::normalize($rules) as $r) {
            if ($r['type'] === 'user' && in_array($r['id'], $users, true)) {
                continue;
            }
            $out[] = ['delay' => $r['delay'] === null ? null : max($r['delay'], $wait)] + $r;
        }

        return AudienceRules::normalize($out);
    }

    /** Сколько минут от выхода идёт эксклюзив: ещё не дан (черновик, выход сейчас) — N дней; идёт — до его конца. */
    public static function wait(Collection $estimates, Offer $offer): int
    {
        $from = $offer->published_at ?? now();

        return (int) ceil($estimates->map(fn (Estimate $e) => $e->exclusive_until ? $from->diffInMinutes($e->exclusive_until) : $e->exclusive_days * AudienceRules::DAY)->max());
    }

    /**
     * Эксклюзив первой волной ленты «Кому показывать» (`x-audience.field exclusive`, 23.11.2026, владелец: «изначально частью
     * волн, чтобы эту волну можно было исключить»): кто, сколько минут, подпись волны, текст «Вы уверены?»; у идущего — до когда.
     *
     * @param  Collection<int, Estimate>  $estimates
     */
    public static function wave(Collection $estimates, Offer $offer): array
    {
        // Хоть один уже идёт (сняли с продажи посреди эксклюзива и вернули) — подпись его концом, как и считается `wait`.
        $running = $estimates->contains(fn (Estimate $e) => $e->exclusive_until && $e->exclusive_until->isFuture());
        $users = $estimates->pluck('user')->filter()->unique('id');

        return [
            'keys' => $users->map(fn ($u) => 'user:'.$u->id)->values()->all(),
            'names' => $users->mapWithKeys(fn ($u) => ['user:'.$u->id => $u->name])->all(),
            'wait' => self::wait($estimates, $offer),
            'label' => 'за оценку, '.($running ? 'до '.\App\Support\Ago::day($estimates->filter(fn (Estimate $e) => $e->exclusive_until)->max('exclusive_until'), true) : Estimate::daysWord((int) $estimates->max('exclusive_days'))),
            'warning' => self::warning($estimates),
            // Идущий у открытого вмешан в его правила (`rules`): снят, а волны не трогали — встают как у вендора (`UpdateOffer`).
            'fallback' => $running ? AudienceRules::normalize($offer->vendor?->audience_rules ?: AudienceRules::everyone()) : null,
        ];
    }

    /** Правила без строк оценщиков (у открытого они вмешаны `rules`) — для ленты, где эксклюзив своей волной. */
    public static function strip(array $rules, Collection $estimates): array
    {
        $users = $estimates->pluck('user_id')->unique()->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter($rules, fn ($r) => ! (($r['type'] ?? null) === 'user' && in_array((int) ($r['id'] ?? 0), $users, true))));
    }

    /** «Роман Корабельников — 5 дней только ему за оценку». */
    public static function words(Collection $estimates): string
    {
        return self::who($estimates).' — '.Estimate::daysWord((int) $estimates->max('exclusive_days')).' только '.($estimates->pluck('user_id')->unique()->count() > 1 ? 'им' : 'ему').' за оценку';
    }
}
