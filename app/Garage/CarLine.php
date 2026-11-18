<?php

namespace App\Garage;

use App\Offers\Deal;
use App\Offers\DealTabs;
use App\Offers\PickupState;
use App\Support\Ago;
use App\Support\Plural;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Машина гаража одной строкой — для плитки, строки и таблицы на сайте и в CRM (10.10.2026: раньше это считали три
 * шаблона каждый по-своему). Этап цветным словом (ждёт машину — словом вывоза, ждут ответа менеджера — «ваш ход»),
 * когда (дни на этапе, у проданной — с какого числа, у рассчитанной — дата) и главное число этапа с подписью.
 * До продажи главное число везде — закупочная (`Car::price`, 13.10.2026: менеджеру, админу на сайте и в CRM), после —
 * итог продажи: отдать нам, платит покупатель, продана за.
 */
final class CarLine
{
    private function __construct(
        public readonly string $word,
        public readonly string $tone,
        public readonly string $when,
        public readonly float|int|null $value,
        public readonly ?string $caption,
        public readonly ?string $who,
    ) {}

    public static function for(Car $car, User $viewer, bool $crm = false): self
    {
        $offer = $car->offer;
        $staff = $viewer->garageStaff();
        $current = $car->invoice;
        // Ход менеджера в сделке со страховой — только самому менеджеру и только на сайте.
        $asks = ! $crm && $car->manager_id === $viewer->id ? $car->deal?->openRequirement : null;
        // Своё дело — обычным цветом и полужирным, просрочено — красным; состояние — серым, не лаймом: лайм — глагол
        // (27.10.2026, владелец: «глянь так же с точки зрения менеджера и покупателя»).
        $mine = 'font-medium text-ink';
        $tone = $asks ? ($asks->due_at?->isPast() ? 'font-medium text-danger' : $mine) : self::tone($car->state->tone());
        // Ждёт машину — словом вывоза: «забрать» (везёт сам — его ход), «стоит у вас», «готовим документы».
        [$pickWord, $pickTone] = $car->isWaiting() ? PickupState::of($offer, $crm ? null : $viewer) : [null, null];
        if (! $crm && $pickTone === 'urgent') {
            $tone = $mine;
        }
        // Покупателю с гаражом его дело — оплатить свой счёт (сумма «к оплате» справа слова не говорила).
        $pay = ! $crm && ! $asks && ! $viewer->garageStaff() && $car->forBuyer() && $car->manager_id === $viewer->id && ! $car->hidesBuyerPrice()
            && ($due = $car->holderInvoice()) && $due->remaining() > 0 && ! $car->buyerHasPaid();
        if ($pay) {
            $tone = $due->isOverdue() ? 'font-medium text-danger' : $mine;
        }
        $word = match (true) {
            // Его дело — само дело, без «ваш ход:» (18.11.2026, владелец: «просто „Оплатить счёт“, всё»).
            $pay => 'оплатить счёт',
            (bool) $asks => mb_lcfirst((string) \App\Users\Wording::for($viewer, $asks->title)),
            // Без «ждёт ТС,» (15.11.2026, владелец: «что это за надпись, зачем она»): слово вывоза и так говорит, что машины ещё нет.
            $pickWord !== null => $pickWord,
            default => mb_lcfirst($car->state->label()),
        };
        $when = match ($car->state) {
            CarState::Settled => (string) Ago::day(($car->settled_at ?? $car->sold_at)),
            // Сколько прошло с продажи, а не «с 12.11» (17.11.2026, владелец: в статусах сроки счётчиком, не датой).
            CarState::Sold => $car->sold_at ? self::days((int) $car->sold_at->copy()->startOfDay()->diffInDays(today())) : '',
            default => self::days($car->stageDays()),
        };
        [$value, $caption] = $crm ? self::crmMoney($car, $current) : self::siteMoney($car, $current, $staff);

        return new self(
            $word,
            $tone,
            $when,
            $value && (float) $value > 0 ? $value : null,
            $value && (float) $value > 0 ? $caption : null,
            // Чья — сотруднику; «забирает Сергей К.» уже назвал того же человека — второй раз не пишем.
            $staff && ! ($car->manager && str_contains($word, $car->manager->shortName())) ? ($car->manager?->shortName() ?? 'взяли под себя') : null,
        );
    }

    /**
     * Статус машин гаража в строках CRM (17.11.2026, владелец: «подготовка, ждём менеджера» под «Переоценкой» — «непонятно»):
     * пока идёт сделка со страховой — статус её шага (`Position::status`, тот же, что у вкладок сделки, без «где машина»), после
     * — этап гаража серым. Шаги — одной выборкой (`DealTabs::rows`): строка базу сама не спрашивает.
     *
     * @param  Collection<int, Car>  $cars
     * @return array<int, array{text: string, tone: string, hint: ?string}|null> по `offer_id`
     */
    public static function crmStatuses(Collection $cars): array
    {
        $open = $cars->filter(fn (Car $c) => $c->dealOpen())->pluck('offer_id')->filter()->all();
        $steps = $open ? DealTabs::rows(Deal::active()->whereIn('offer_id', $open)->with(DealTabs::WITH)->get())
            ->mapWithKeys(fn (array $r) => [$r['deal']->offer_id => $r['deal']->offer?->position()?->status()])->all() : [];

        return $cars->mapWithKeys(fn (Car $c) => [$c->offer_id => in_array($c->offer_id, $open, true)
            ? ($steps[$c->offer_id] ?? null)
            : ['text' => mb_lcfirst($c->state->label()), 'tone' => self::tone($c->state->tone()), 'hint' => null]])->all();
    }

    /** «3 дня»: число и слово не разрываются переносом. */
    public static function days(int $d): string
    {
        return $d."\u{00A0}".Plural::of($d, ['день', 'дня', 'дней']);
    }

    private static function tone(string $tone): string
    {
        return match ($tone) {
            'urgent' => 'text-urgent',
            'closed' => 'text-ink-dim',
            default => 'text-ink-muted',
        };
    }

    private static function siteMoney(Car $car, $current, bool $staff): array
    {
        // Покупатель с гаражом (20.10.2026): его цена, а пока его счёт не оплачен — остаток; закупочной ему нет.
        if (! $staff && $car->forBuyer()) {
            if ($car->hidesBuyerPrice()) {
                return [$car->offer?->asking_price, 'цена продажи'];
            }
            $due = $car->holderInvoice();

            return $due && $due->remaining() > 0 ? [$due->remaining(), 'к оплате'] : [$car->buyer_price, 'цена'];
        }

        return match ($car->state) {
            // До продажи — закупочная: сотруднику нынешняя, менеджеру закреплённая при подтверждении (16.10.2026).
            CarState::Waiting, CarState::Repair, CarState::Ready, CarState::Selling => [$car->priceFor($staff), 'закупочная'],
            CarState::Sold => $current && $current->remaining() > 0
                ? [$current->remaining(), $car->invoice_to === 'buyer' ? 'платит покупатель' : ($staff ? 'отдаёт нам' : 'отдать нам')]
                : [$car->sold_price, $staff ? 'счёта нет' : 'продана за'],
            default => [$car->sold_price, 'продана за'],
        };
    }

    private static function crmMoney(Car $car, $current): array
    {
        return match ($car->state) {
            CarState::Waiting, CarState::Repair, CarState::Ready, CarState::Selling => [$car->price(), 'закупочная'],
            CarState::Sold => $current && $current->remaining() > 0
                ? [$current->remaining(), $current->isOwed() ? 'отдаём' : ($car->invoice_to === 'buyer' ? 'платит покупатель' : 'отдаёт нам')]
                : [$car->sold_price, $car->invoice ? 'продана за' : 'счёта нет'],
            default => [$car->sold_price, 'продана за'],
        };
    }
}
