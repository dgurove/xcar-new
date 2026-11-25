<?php

namespace App\Offers;

use App\Http\Admin\OfferController;
use App\Http\Admin\PickupController;
use App\Logistics\Transport;
use App\Notifications\Notice;
use App\Users\User;

/**
 * Ответственность сотрудника — одна дверь (26.11.2026, владелец по просьбам сотрудников: «у Кати во вывозе красным только
 * просроченные и где срок обязывающего скоро сгорит», «уведомления ей только по назначению исполнителя», «Андрею мозг по оплатам
 * за ТС не выносить»). Кто за что — «Настройки → Ответственные» (`TabOwner`): вкладка, `scope` (`all` — вся, `urgent` — только
 * горящее), `notify` (пуш и Telegram по её строкам); строка `BINDING` — горящий срок обязывающего во всех вкладках (красным, без
 * уведомлений).
 *
 * - Горящее строки: срок обязывающего горит (`Binding::panicSql`); у сделки — шаг просрочен (`Position::isOverdue`); у вывоза —
 *   день вывоза прошёл, сутки без шага, сутки без исполнителя или документы не ушли (`lateSql`).
 * - Красный счётчик колеса и таб-бара — только своё (`wheel`, `total`); главный администратор видит всё, как раньше.
 * - Уведомление сотруднику пушем и в Telegram — только по своему (`wants`); не своё — строкой ленты, без всплывашки. У вкладки
 *   нет подписанных — тем, кто отвечает за её раздел (сводная «Все сделки» и т. п.), иначе как раньше всем.
 */
final class Responsibility
{
    public const ALL = 'all';

    public const URGENT = 'urgent';

    /** Не вкладка: горящий срок обязывающего во всех вкладках — красным счётчиком, без уведомлений и без «Только мои». */
    public const BINDING = 'binding';

    /**
     * Вывоз горит (одно условие для подгруппы вкладки и счётчика): день вывоза прошёл без «Забрал» (`Transport::isLate`), сутки у
     * логиста или назначен без созвона (`TickCommand::stuck`), сутки без исполнителя, документы СДЭКом не ушли за сутки.
     */
    public static function lateSql(string $t = 'transports'): string
    {
        $day = now()->subDay()->toDateTimeString();
        $today = now()->startOfDay()->toDateTimeString();

        return "(({$t}.state = 'agreed' and {$t}.pickup_at < '{$today}')"
            ." or ({$t}.state in ('logist', 'assigned') and {$t}.assigned_at < '{$day}')"
            ." or ({$t}.state = 'new' and {$t}.undecided = false and {$t}.executor_id is null and {$t}.created_at < '{$day}')"
            ." or ({$t}.docs_by_cdek and {$t}.docs_sent_at is null and {$t}.docs_received_at is null and {$t}.executor_id is not null"
            ." and {$t}.state in ('logist', 'assigned', 'agreed') and {$t}.assigned_at < '{$day}'))";
    }

    /**
     * Горит срок обязывающего — для ответственных и подгрупп: машина без покупателя (`Binding::buyerlessSql`). Сделка, что не
     * заплатила поставщику, не горит (26.11.2026, владелец: «на счёте постоянно бабок нету, это только нервирует»).
     */
    public static function hotSql(string $table = 'offers'): string
    {
        // С 26.11.2026 — только без покупателя: неоплата поставщику не горит (владелец: «на мозги капать не надо»).
        return Binding::buyerlessSql($table);
    }

    /** Подпись подгрупп горящего в вывозе и сделках — те же, что у предложений (`OfferController::HOT_GROUP`). */
    public const LATE_GROUP = 'Просрочено';

    /**
     * Красные счётчики колеса глазами человека: [числа, вкладка → ключ числа]. Своя вкладка целиком — прежнее «наше дело»
     * (`OfferController::badges`); только горящее — горящие строки (`{вкладка}_urgent`, у вкладок без своих сроков —
     * `{вкладка}_hot`); `BINDING` — горящий обязывающий в любой вкладке. Не своё — без красного. Главный администратор (не «глазами
     * сотрудника») — как раньше.
     *
     * @param  array<string, int>  $counts  `OfferController::counts`
     * @return array{0: array<string, int>, 1: array<string, string>}
     */
    public static function wheel(User $viewer, array $counts, ?int $as = null): array
    {
        $badges = array_diff_key(OfferController::badges(), ['all_deals' => true]);
        $whose = $as ? User::find($as) : $viewer;
        // Главный администратор и сотрудник без «Ответственных» — как раньше: все красные счётчики.
        if (! $whose || ($whose->isOwner() && ! $as) || ! self::assigned($whose)) {
            return [$counts, $badges];
        }
        $rows = TabOwner::of($whose);
        $watch = isset($rows[self::BINDING]);
        $out = [];
        foreach (array_keys(OfferController::PRESETS) as $key) {
            if (array_key_exists($key, OfferController::SUMMARIES) || $key === 'all') {
                continue;
            }
            $row = $rows[$key] ?? null;
            $hot = $counts[$key.'_hot'] ?? 0;
            $n = match (true) {
                $row?->scope === self::URGENT => $counts[$key.'_urgent'] ?? $hot,
                // Своя целиком: её дела; горящего обязывающего больше, чем дел, — им (строки горящего обычно и есть дела).
                $row !== null => isset($badges[$key]) ? max($counts[$badges[$key]] ?? 0, $watch ? $hot : 0) : ($watch ? $hot : null),
                $watch && $hot > 0 => $hot,
                default => null,
            };
            if ($n === null) {
                continue;
            }
            $counts[$key.'_mine'] = $n;
            $out[$key] = $key.'_mine';
        }

        return [$counts, $out];
    }

    /**
     * Сколько своего по своим вкладкам — бейдж «Предложений» в таб-баре CRM. Горящий обязывающий чужих вкладок (`BINDING`) сюда не
     * входит: с «Только мои» этих вкладок не видно, а число звало бы туда.
     */
    public static function total(User $user): int
    {
        [$counts, $badges] = self::wheel($user, OfferController::counts($user));
        $own = array_intersect_key($badges, TabOwner::of($user));

        return array_sum(array_map(fn (string $k) => (int) ($counts[$k] ?? 0), $own));
    }

    /** Отвечает ли человек за что-нибудь (иначе — всё как раньше: «Ответственных» ему не задали). */
    public static function assigned(User $user): bool
    {
        return TabOwner::of($user) !== [];
    }

    /**
     * Пуш и Telegram этому человеку — его ли это (`Notice::via`). Не сотрудник, главный администратор, личка, уведомление не о
     * строке вкладки — да. Иначе — вкладки уведомления (`Notice::tabs`): его вкладка с `notify` целиком — да; «только горящее» —
     * если уведомление о горящем (`Notice::urgent`). У вкладки нет никого с `notify` — тем, кто отвечает за раздел (`fallback`).
     */
    public static function wants(User $user, Notice $notice): bool
    {
        if (! $user->isStaff() || $user->isOwner() || ! self::assigned($user)) {
            return true;
        }
        $tabs = $notice->tabs();
        if ($tabs === null || $notice->personalTo($user)) {
            return true;
        }
        $rows = TabOwner::rows();
        $urgent = null;
        foreach ($tabs as $tab) {
            $subscribed = $rows->where('preset', $tab)->where('notify', true);
            $mine = $subscribed->firstWhere('user_id', $user->id);
            if ($mine && ($mine->scope !== self::URGENT || ($urgent ??= $notice->urgent()))) {
                return true;
            }
            // У вкладки нет ответственных вовсе (вкладка ожидания, никого не назначили) — раздел: «Все сделки», «Все черновики»…
            // Ответственные есть, но без уведомлений (Катя на «Оплатить поставщику») — тихо, другим не перекладываем.
            if ($rows->where('preset', $tab)->isEmpty() && ($section = self::section($tab)) !== null
                && $rows->where('preset', $section)->where('user_id', $user->id)->where('notify', true)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /** Сводная раздела вкладки — у неё ответственные за раздел целиком (`OfferController::SUMMARIES`). */
    private static function section(string $tab): ?string
    {
        if (isset(DealTabs::TABS[$tab]) || $tab === 'finished') {
            return 'all_deals';
        }
        foreach (OfferController::SUMMARIES as $key => $tabs) {
            if (in_array($tab, (array) $tabs, true)) {
                return $key;
            }
        }

        return isset(PickupController::TABS[$tab]) ? 'pickup_assign' : 'all';
    }

    /**
     * Вкладки, где машина сейчас (`Notice::tabs` по умолчанию): своя вкладка раскладки — сделка посчитана заново, не из кэша
     * (уведомление уходит сразу после шага), — и вкладки вывоза, где её вывоз.
     *
     * @return list<string>
     */
    public static function tabsOf(Offer $offer): array
    {
        $tabs = [];
        $deal = $offer->state === OfferState::Sold ? Deal::active()->where('offer_id', $offer->id)->with(DealTabs::WITH)->first() : null;
        if ($deal && ! $deal->sellsFromGarage($deal->offer?->position()?->stage)) {
            $tab = DealTabs::rows(collect([$deal]))->first()['tab'];
            // «Ждём оплату»: за ТС — «Ждём оплату ТС», за подбор — «Ждём оплату подбора», подбор в счёте за ТС — обе (`payingParts`).
            $parts = $tab === 'paying' ? DealTabs::payingParts($deal) : [];
            if ($tab !== 'paying' || in_array('car', $parts, true)) {
                $tabs[] = $tab;
            }
            if (in_array('fee', $parts, true) || in_array($offer->id, \App\Http\Admin\SelectionController::ids(), true)) {
                $tabs[] = 'paying_fee';
            }
        } else {
            $tabs[] = OfferTabs::fresh($offer);
        }
        if ($offer->car_id) {
            foreach (array_keys(PickupController::TABS) as $tab) {
                if (PickupController::query($tab)->where('transports.car_id', $offer->car_id)->exists()) {
                    $tabs[] = $tab;
                }
            }
        }

        return array_values(array_unique($tabs));
    }

    /** Горит ли строка машины: срок обязывающего, просроченный шаг сделки, просроченный вывоз. */
    public static function urgentOffer(Offer $offer): bool
    {
        if (Offer::whereKey($offer->id)->whereRaw(self::hotSql())->exists()) {
            return true;
        }
        $deal = $offer->state === OfferState::Sold ? Deal::active()->where('offer_id', $offer->id)->first() : null;
        if ($deal && $offer->position()?->isOverdue()) {
            return true;
        }

        return $offer->car_id !== null && Transport::where('car_id', $offer->car_id)->where('current', true)->whereRaw(self::lateSql())->exists();
    }
}
