<?php

namespace App\Offers;

use App\Support\ShowMemo;
use App\Users\Wording;
use App\Workflow\Path;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Чей ход в сделке — глазами менеджера (18.11.2026, владелец: «им должно быть всё равно, что мы там внутри делаем: есть ЕГО шаг
 * — сколько времени осталось — и НАШ шаг — сколько времени он идёт, ВСЁ»). Его ход — само дело (`Deal::managerTask`) и срок;
 * наш — одно из двух слов: «На согласовании», пока он не взял ТС в работу, и «В обработке» — всё остальное, с начала нашего
 * отрезка (ход поставщика — тоже наш: что мы ждём поставщика, менеджеру знать не нужно). Одна дверь для шага сделки, пути,
 * строк «Сделок» и гаража.
 */
final class Turn
{
    public const AGREEING = 'На согласовании';

    public const WORKING = 'В обработке';

    private function __construct(
        public readonly bool $mine,
        public readonly string $title,
        public readonly ?Carbon $until,
        public readonly ?Carbon $since,
    ) {}

    /**
     * `$task` — `Deal::managerTask()`, если уже посчитан (списки считают его раз на сделку; `false` — посчитать здесь).
     * `$list` — для строк «Сделок»: гаражная без просьбы там не дело (продажа из гаража идёт неделями, `Deal::managerTask`).
     */
    public static function of(Deal $deal, ?Position $position = null, array|false|null $task = false, bool $list = false): ?self
    {
        if (! $deal->isLive()) {
            return null;
        }
        $position ??= $deal->offer?->position(Track::Sale);
        if (! $position) {
            return null;
        }
        $task = $task === false ? $deal->managerTask() : $task;
        if ($task ??= $list ? null : self::garageTask($deal, $position)) {
            $title = $task['title'];
            // Оплата поставщику — делом без суммы (18.11.2026, владелец: «просто „Оплатить счёт“, всё»): сумма — строкой денег ниже.
            if (($task['kind'] ?? null) === 'ask' && ($label = $deal->schemeOf()->payLabel()) && str_starts_with($title, $label.' ')) {
                $title = $label;
            }
            // Согласие — вопросом: «Взять в работу?».
            if (($task['kind'] ?? null) === 'ask' && $deal->openRequirement?->stage?->asksConsent() && ! str_ends_with($title, '?')) {
                $title .= '?';
            }

            return new self(true, (string) Wording::for($deal->buyer, $title), $task['due'] ?? null, null);
        }

        return new self(false, self::agreeing($deal, $position) ? self::AGREEING : self::WORKING, null, self::since($deal, $position));
    }

    /**
     * Ход менеджера по машине гаража после покупки (18.11.2026): подсказки пути («укажите покупателя», «оплата, осталось 2д»)
     * сняты — дело встаёт той же шапкой, что у сделки. Забрать машину самому, назвать покупателя счёта Совкомбанка, оплатить
     * свой счёт. Остальное — этапы пути, шапки нет. Данные — `GarageView`.
     *
     * @param  array<string, mixed>  $view
     */
    public static function ofGarage(\App\Garage\Car $car, \App\Users\User $viewer, array $view): ?self
    {
        if ($view['staff'] || $car->dealOpen()) {
            return null;
        }
        if ($view['holderDue'] ?? false) {
            return new self(true, 'Оплатить счёт', $view['holderInvoice']?->due_at, null);
        }

        return match (true) {
            $car->isWaiting() => PickupState::of($car->offer, $viewer)[1] === 'urgent' ? new self(true, 'Забрать ТС', null, null) : null,
            $car->state === \App\Garage\CarState::Sold && $car->settledByDeal() => match ($car->deal?->invoiceGap()) {
                'buyer' => new self(true, 'Указать покупателя', null, null),
                'passport' => new self(true, 'Приложить паспорт покупателя', null, null),
                default => null,
            },
            $car->state === \App\Garage\CarState::Sold && $view['unpaid'] && $car->invoice_to !== 'buyer' => new self(true, 'Оплатить счёт', $view['current']?->due_at, null),
            default => null,
        };
    }

    /**
     * Путь сделки ходами (18.11.2026, владелец выбрал из двух: «ходами», а не нашими этапами): «Подтверждение принято», дальше
     * журнал склеен по сторонам — его шаг своим заголовком («Взять в работу», «Нужны реквизиты»), подряд наши и поставщика —
     * одним «На согласовании» / «В обработке»; у пройденного — сколько длилось, текущее — как шапка шага (`of`), впереди —
     * серым только его шаги по главной дороге маршрута (`Path::ladder … guess`) и конец («Сделка закрыта»). Подпунктов нет.
     *
     * @param  Collection<int, array{at: Carbon, block: string, stage_id: ?int}>  $journal  `Path::journal` с начала сделки
     * @param  Collection<int, \App\Workflow\Block>  $blocks  `Path::ladder` с этапами
     * @return list<array{title: string, state: string, mine: bool, at: ?Carbon, took: ?int, turn: ?self}>
     */
    public static function path(Deal $deal, ?Position $position, Collection $journal, Collection $blocks): array
    {
        $turn = self::of($deal, $position);
        $reqs = $deal->requirements->loadMissing('stage.exits')->sortBy('id')->values();
        $consentAt = $reqs->first(fn ($r) => $r->done_at && $r->stage?->asksConsent())?->done_at;
        $workflow = $position?->stage?->workflow_id ?? $blocks->first()?->workflow_id;
        $agreement = $workflow ? self::agreementBlock($workflow) : null;
        $ids = $journal->pluck('stage_id')->filter()->unique()->values();
        $stages = $ids->isEmpty() ? collect() : ShowMemo::get('turn-stages:'.$ids->implode(','), fn () => Stage::with(['exits', 'block'])->whereIn('id', $ids)->get()->keyBy('id'));

        $items = [['title' => 'Подтверждение принято', 'mine' => false, 'at' => $deal->created_at, 'lead' => true]];
        // До этапа согласия по журналу — «На согласовании» (порядком, а не временем: этапы бывают в одну секунду).
        $afterConsent = false;
        foreach ($journal as $e) {
            $stage = $stages[$e['stage_id']] ?? null;
            if ($stage && $stage->exits->isEmpty()) {
                // Конец пути — его словом («Сделка закрыта», «Отказ поставщика»), датой.
                $items[] = ['title' => (string) Wording::for($deal->buyer, $e['block']), 'mine' => false, 'at' => $e['at'], 'lead' => true];

                continue;
            }
            $req = $stage && $stage->waits_for === WaitsFor::Manager
                ? $reqs->first(fn ($r) => $r->stage_id === $stage->id && $r->created_at->gte($e['at']->copy()->subMinute())) : null;
            // Его шаг — нынешним заголовком этапа: просьба хранит слова на момент, когда её задали («Заполните…» до 09.04).
            $title = $req ? self::askTitle($deal, $stage, null)
                : (! $afterConsent && $agreement !== null && ($stage?->block?->position ?? PHP_INT_MAX) <= $agreement ? self::AGREEING : self::WORKING);
            $afterConsent = $afterConsent || (bool) $stage?->asksConsent();
            $last = end($items);
            if ($last && empty($last['lead']) && $last['title'] === $title && $last['mine'] === (bool) $req) {
                continue;
            }
            $items[] = ['title' => $title, 'mine' => (bool) $req, 'at' => $e['at']];
        }

        $out = [];
        $live = $turn !== null;
        // Текущее — шапка шага: последний отрезок той же стороны ею и становится, иначе она встаёт следом.
        if ($live) {
            $last = end($items);
            if ($last && empty($last['lead']) && $last['mine'] === $turn->mine) {
                array_pop($items);
                $at = $last['at'];
            } else {
                $at = $turn->since ?? now();
            }
        }
        foreach ($items as $i => $it) {
            $next = $items[$i + 1]['at'] ?? ($live ? $at : null);
            $out[] = ['title' => $it['title'], 'state' => 'done', 'mine' => $it['mine'], 'at' => ! empty($it['lead']) ? $it['at'] : null,
                'took' => empty($it['lead']) && $next ? max(60, (int) $it['at']->diffInSeconds($next)) : null, 'turn' => null];
        }
        if (! $live) {
            return $out;
        }
        $out[] = ['title' => $turn->title, 'state' => 'current', 'mine' => $turn->mine, 'at' => null, 'took' => null, 'turn' => $turn];

        // Впереди — его шаги по главной дороге: оставшиеся этапы текущего блока и блоки за ним.
        $seen = array_column($out, 'title');
        $current = $position?->stage;
        $index = $blocks->search(fn ($b) => $b->id === $current?->block_id);
        foreach ($blocks->slice($index === false ? $blocks->count() : $index) as $block) {
            if ($block->isFinal()) {
                $out[] = ['title' => (string) Wording::for($deal->buyer, $block->name), 'state' => 'next', 'mine' => false, 'at' => null, 'took' => null, 'turn' => null];

                break;
            }
            foreach ($block->stages as $stage) {
                if ($block->id === $current->block_id && $stage->position <= $current->position) {
                    continue;
                }
                if ($stage->waits_for !== WaitsFor::Manager || ! $stage->ask_title || ($consentAt && $stage->asksConsent())) {
                    continue;
                }
                $title = self::askTitle($deal, $stage, null);
                if (! in_array($title, $seen, true)) {
                    $seen[] = $title;
                    $out[] = ['title' => $title, 'state' => 'next', 'mine' => true, 'at' => null, 'took' => null, 'turn' => null];
                }
            }
        }

        return $out;
    }

    /** Его шаг словами: просьба или заголовок этапа; оплата поставщику — «Оплатить поставщику» без суммы. */
    private static function askTitle(Deal $deal, Stage $stage, ?string $title): string
    {
        $title = $title ?? $stage->managerTitle($deal);
        if ($stage->isPayStep() && $deal->paysSupplierOn($stage) && ($label = $deal->schemeOf()->payLabel())) {
            $title = $label;
        }

        return (string) Wording::for($deal->buyer, rtrim($title, '?'));
    }

    /**
     * Гаражная сделка Совкомбанка на шаге оплаты: счёт встанет, когда менеджер назовёт покупателя или цену продажи
     * (`Deal::invoiceGap`). В «Ваш ход» списка она не встаёт (`Deal::managerTask`), а на её странице это его дело.
     *
     * @return array{title: string, due: ?Carbon, kind: string}|null
     */
    private static function garageTask(Deal $deal, Position $position): ?array
    {
        if (! $deal->isGarage() || ! $position->stage?->isPayStep() || ! $deal->hasContract()) {
            return null;
        }

        return match ($deal->invoiceGap()) {
            'buyer' => ['title' => 'Указать покупателя', 'due' => null, 'kind' => 'buyer'],
            'passport' => ['title' => 'Приложить паспорт покупателя', 'due' => null, 'kind' => 'passport'],
            'price' => ['title' => 'Указать цену продажи', 'due' => null, 'kind' => 'price'],
            default => null,
        };
    }

    /**
     * «На согласовании» — пока менеджер не взял ТС в работу: согласия ещё не давал, и сделка не ушла дальше блока, где его
     * спрашивают. У маршрута без согласия (Совкомбанк) — пока идёт блок «Согласование с поставщиком».
     */
    private static function agreeing(Deal $deal, Position $position): bool
    {
        $block = $position->stage?->block;
        if (! $block) {
            return false;
        }
        $answered = $deal->requirements->contains(fn ($r) => $r->done_at !== null && $r->stage?->asksConsent());
        if ($answered) {
            return false;
        }
        $agreement = self::agreementBlock($block->workflow_id);

        return $agreement !== null && $block->position <= $agreement;
    }

    /** Позиция блока, где менеджера спрашивают «Взять в работу?» (иначе — «Согласование с поставщиком») в маршруте. */
    private static function agreementBlock(int $workflowId): ?int
    {
        return ShowMemo::get('turn-agreement:'.$workflowId, function () use ($workflowId) {
            $stages = Stage::with(['block', 'exits'])->whereHas('block', fn ($b) => $b->where('workflow_id', $workflowId))->get();
            $consent = $stages->first(fn (Stage $s) => $s->asksConsent());

            return $consent?->block?->position
                ?? $stages->first(fn (Stage $s) => $s->block?->name === 'Согласование с поставщиком')?->block?->position;
        });
    }

    /**
     * С какого момента ход наш: по журналу пути назад, пока этапы не его (наш и поставщика — один отрезок, счётчик не
     * сбрасывается, когда письмо поставщику сменилось его ответом). Стоим на его этапе, а дело уже сделано (оплатил, счёт
     * готовим) — с его последнего ответа.
     */
    private static function since(Deal $deal, Position $position): ?Carbon
    {
        $journal = Path::journal($deal->offer, Track::Sale, $deal->created_at);
        $answered = $deal->requirements->whereNotNull('done_at')->max('done_at');
        $waits = self::waits($journal);
        $from = null;
        foreach ($journal->reverse() as $entry) {
            if (($waits[$entry['stage_id']] ?? null) === WaitsFor::Manager) {
                break;
            }
            $from = $entry['at'];
        }
        $from ??= $journal->last()['at'] ?? $position->entered_at;
        if ($answered && $from && $answered->gt($from) && ($waits[$journal->last()['stage_id'] ?? 0] ?? null) === WaitsFor::Manager) {
            $from = $answered;
        }

        return $from ?? $deal->created_at;
    }

    /** @return array<int, WaitsFor> кого ждёт этап журнала */
    private static function waits(Collection $journal): array
    {
        $ids = $journal->pluck('stage_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return ShowMemo::get('turn-waits:'.$ids->implode(','), fn () => Stage::whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Stage $s) => [$s->id => $s->waits_for])->all());
    }
}
