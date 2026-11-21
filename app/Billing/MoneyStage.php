<?php

namespace App\Billing;

use App\Support\Facets\Facet;
use App\Support\Facets\Option;
use App\Workflow\Outcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Статус сделки в «Оплатах» (22.11.2026, владелец: «добавить столбец „Статус“ — этап, на котором сделка сейчас, и фильтр»;
 * «по умолчанию все этапы, кроме тех, что до того, как менеджер нажал „Беру в работу“: с этого момента можно выставлять
 * ссылки, отказаться они уже не могут»). Одно выражение на строку и на чип — что написано в строке, по тому и фильтр:
 * - `untaken` «Не взял в работу» — шаг в блоке шага «Согласие менеджера» (выход «Беру в работу», `Outcome::TAKES`) и не позже
 *   него: у Альфы и «Письмо о покупке» до согласия, у Т-Страхования — «Ждём письмо поставщика»; у маршрутов без этого шага
 *   (Совкомбанк, Каркаде, ТИТ, ИНСАЙТ) менеджер в работе с принятия;
 * - `cancelled` «Сделка отменена»;
 * - иначе блок шага по имени тем же ключом, что чип «Этап» «Сделок» (`left(md5(name), 8)`): одноимённые блоки разных
 *   вендоров — один вариант; без позиции — «Сделка закрыта», «Гараж» или «Без этапа».
 * По умолчанию — всё, кроме `untaken` и `cancelled` (`DEFAULT`, умолчанием экрана `Facets::implied`).
 */
final class MoneyStage
{
    public const UNTAKEN = 'untaken';

    public const CANCELLED = 'cancelled';

    /** Умолчание чипа: «Кроме „Не взял в работу“ и отменённых». */
    public const DEFAULT = '!'.self::UNTAKEN.','.self::CANCELLED;

    /** Свои ключи (не блоки маршрута) и их подписи. */
    private const OWN = [self::UNTAKEN => 'Не взял в работу', 'garage' => 'Гараж', 'none' => 'Без этапа', self::CANCELLED => 'Сделка отменена'];

    /** Закрытая сделка без позиции — тем же вариантом, что блок «Сделка закрыта». */
    private const CLOSED = 'Сделка закрыта';

    /** Порядок блоков по ходу сделки; прочие — за ними по месту в маршруте. */
    private const ORDER = ['Согласование с поставщиком', 'Собираем заявку поставщику', 'Заявка у поставщика', 'Реквизиты для счёта', 'Оплата',
        'Получение автомобиля', 'Договор', 'Оформление документов', 'Расчёт с поставщиком', 'Взяли под себя', 'Оформляем на нас',
        'Покупатель от поставщика', self::CLOSED];

    /** Ключ статуса сделки (`deals`) в SQL. */
    public static function sql(string $deals = 'deals'): string
    {
        $takes = implode(', ', array_map(fn (string $l) => "'".str_replace("'", "''", $l)."'", Outcome::TAKES));
        $closed = self::blockKey(self::CLOSED);

        return "(case
            when {$deals}.state = 'cancelled' then '".self::CANCELLED."'
            when {$deals}.closed_at is null and exists (select 1 from offer_positions mp join workflow_stages ms on ms.id = mp.stage_id
                join workflow_stages mc on mc.workflow_id = ms.workflow_id and mc.block_id = ms.block_id and mc.position >= ms.position
                join workflow_exits me on me.stage_id = mc.id and me.actor = 'manager' and lower(trim(me.label)) in ({$takes})
                where mp.offer_id = {$deals}.offer_id and mp.track = 'sale') then '".self::UNTAKEN."'
            else coalesce((select left(md5(coalesce(mb.name, ms.name)), 8) from offer_positions mp join workflow_stages ms on ms.id = mp.stage_id
                left join workflow_blocks mb on mb.id = ms.block_id where mp.offer_id = {$deals}.offer_id and mp.track = 'sale' order by mp.id limit 1),
                case when {$deals}.closed_at is not null then '{$closed}' when {$deals}.garage_payer is not null then 'garage' else 'none' end)
        end)";
    }

    /** Ключ блока по имени — как у чипа «Этап» «Сделок». */
    private static function blockKey(string $name): string
    {
        return substr(md5($name), 0, 8);
    }

    /**
     * Подписи всех ключей по порядку: «Не взял в работу», блоки по ходу сделки, «Гараж», «Без этапа», «Сделка отменена».
     * Блоки — из маршрутов (минута в кэше: меняются редко).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $blocks = Cache::remember('money-stage-blocks', 60, fn () => DB::table('workflow_stages as s')->leftJoin('workflow_blocks as b', 'b.id', '=', 's.block_id')
            ->selectRaw('coalesce(b.name, s.name) as name, min(coalesce(b.position, s.position)) as pos')->groupByRaw('coalesce(b.name, s.name)')
            ->get()->sortBy(fn ($r) => [($i = array_search($r->name, self::ORDER, true)) === false ? count(self::ORDER) : $i, (int) $r->pos, $r->name])
            ->mapWithKeys(fn ($r) => [self::blockKey($r->name) => $r->name])->all());
        $blocks += [self::blockKey(self::CLOSED) => self::CLOSED];

        return [self::UNTAKEN => self::OWN[self::UNTAKEN]] + $blocks + array_diff_key(self::OWN, [self::UNTAKEN => 0]);
    }

    /** Подпись ключа строки. */
    public static function label(?string $key): ?string
    {
        return $key === null ? null : (self::labels()[$key] ?? null);
    }

    /** Чип «Статус» по сделкам (`deals` или своё имя таблицы). */
    public static function facet(string $deals = 'deals'): Facet
    {
        return Facet::column('stage', 'Статус', ['статус', 'статуса', 'статусов'], self::sql($deals))->icon('route')->natural()
            ->labels(fn (array $keys) => collect(self::labels())->only($keys)->map(fn (string $l, string $k) => new Option($k, $l))->all());
    }
}
