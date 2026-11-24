<?php

namespace App\Offers\Actions;

use App\Billing\Bank\Actions\BuyerFromStatement;
use App\Billing\Bank\Transaction;
use App\Billing\Robot;
use App\Garage\Actions\MarkGarageSold;
use App\Garage\Car;
use App\Offers\Deal;
use App\Offers\Margin;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Гаражная продажа догадкой по выписке (18.11.2026, владелец: «оплаты со СберБизнеса не разносятся сами, висят тупо»;
 * 28 гаражных сделок стояли «Деньги пришли: записать продажу» — покупатель заплатил по VIN, цену никто не вписал).
 * Заплатил покупатель, а не менеджер, — машина «Продана», покупатель сделки — он же (`BuyerFromStatement`). Пришло нам —
 * цена минус вознаграждение (18.11.2026: вознаграждение менеджер получает не от нас): из пришедшего закупочная — за ТС,
 * остальное — услуги подбора. Сколько менеджер взял с покупателя сверху, мы не знаем — цена продажи по догадке = пришедшее,
 * вознаграждение 0 (план владельца: «цену = поступление, вознаграждение не ставит»; досчитанное по шкале давало выдуманные цены
 * вроде 629 207). Счёт ПРАЙМ встаёт ровно на пришедшее и закрыт им. Это догадка, и в «Истории» она так и названа (владелец:
 * «главное — в истории помечать, что это догадки»): «Продана за N — догадка по выписке: …», «Вознаграждение не знаем — догадка
 * по выписке».
 *
 * Не гадает, если: продажа уже записана или цена сделки вписана, машину ещё везут, платил сам менеджер или платили разные
 * люди, есть деньги мимо выписки, сумма в минус или с лишним нулём против закупочной. Тогда остаётся «Деньги пришли:
 * записать продажу», как было. Поступление после продажи-догадки цену не трогает — строка в «Истории»: проверьте.
 * Сама — из `MatchByVin` на новое поступление; прошлые — по одной, `garage:sell-from-statement` (владелец: «не массовым
 * скриптом, а по одному внимательно»).
 */
final class SellFromStatement
{
    public function __construct(private MarkGarageSold $sold) {}

    /**
     * Что записать, или почему нельзя.
     *
     * @return array{ok: bool, why?: string, price?: int, paid?: int, reward?: ?int, payer?: string, txs?: Collection<int, Transaction>, car?: Car}
     */
    public static function guess(Deal $deal): array
    {
        $deal->loadMissing(['garageCar.manager', 'buyer', 'offer']);
        $car = $deal->garageCar;
        $no = fn (string $why) => ['ok' => false, 'why' => $why];
        if (! $car) {
            return $no('не гаражная');
        }
        if ($car->isSold()) {
            return $no('продажа уже записана');
        }
        if ($deal->amount !== null) {
            return $no('цена продажи уже вписана');
        }
        if ($car->forBuyer()) {
            return $no('машина у покупателя с гаражом');
        }
        if (! $car->state->isWorking()) {
            return $no('машину ещё везут');
        }
        $txs = self::arrivals($deal);
        if ($txs->isEmpty()) {
            return $no('по выписке ничего не пришло');
        }
        $payers = $txs->map(fn (Transaction $tx) => BuyerFromStatement::payer($tx, $deal)['name'] ?? null);
        if ($payers->contains(null)) {
            return $no('платил сам менеджер или в выписке нет имени');
        }
        // Платил другой менеджер системы (18.11.2026: Tiggo 4 Pro Шералиева оплатил Иван Заикин) — это не покупатель, не гадаем.
        if ($colleague = $payers->first(fn ($n) => self::managerNamed($n))) {
            return $no('платил менеджер: '.$colleague);
        }
        if ($payers->map(fn ($n) => mb_strtolower($n))->unique()->count() > 1) {
            return $no('платили разные люди: '.$payers->unique()->implode(', '));
        }
        $price = (int) round($txs->sum('amount'));
        // Деньги мимо выписки (ссылка, «Оплачено вручную») — сумма выписки не вся цена.
        if ($deal->credited() > $txs->sum('amount') + 0.5) {
            return $no('есть оплаты не из выписки');
        }
        $check = Margin::check($price, $deal->cost === null ? null : (int) $deal->cost);
        if ($check && in_array($check['tone'], ['typo', 'loss'], true)) {
            return $no('сумма против закупочной: '.mb_lcfirst($check['title']));
        }

        // Гаражная Совкомбанка — вознаграждение 0 (не знаем, счёт тогда ровно на пришедшее); «Себе» — продали мы, его нет вовсе.
        $reward = $deal->namesBuyer() ? 0 : null;

        return ['ok' => true, 'price' => $price, 'paid' => $price, 'reward' => $reward, 'payer' => $payers->first(), 'txs' => $txs, 'car' => $car];
    }

    /** Имя из выписки — это кто-то из менеджеров системы: все слова его имени есть среди слов плательщика. */
    /**
     * Предоплата гаража (24.11.2026, владелец: «это предоплата»): все поступления по выписке — от самого менеджера (в выписке
     * его имя, `BuyerFromStatement::payer` не находит покупателя) или от другого менеджера системы. Машину ещё не продали —
     * дела «Деньги пришли: записать продажу» нет: цену назовёт менеджер, когда продаст («Продаю»).
     */
    public static function prepaid(Deal $deal): bool
    {
        $txs = self::arrivals($deal);

        return $txs->isNotEmpty() && $txs->every(function (Transaction $tx) use ($deal) {
            $name = BuyerFromStatement::payer($tx, $deal)['name'] ?? null;

            return $name === null || self::managerNamed($name);
        });
    }

    private static function managerNamed(string $payer): bool
    {
        $words = fn (string $n) => array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($n))), fn ($w) => mb_strlen($w) > 1));
        $have = $words($payer);
        // `once`, а не `static`: воркер Octane живёт долго, а менеджеры заводятся.
        $managers = once(fn () => \App\Users\User::withRole(\App\Users\Role::Manager)->pluck('name')->map($words)->filter(fn ($w) => count($w) >= 2)->all());

        return collect($managers)->contains(fn ($w) => ! array_diff($w, $have));
    }

    /** Поступления выписки по сделке — деньги за машину, по дате. */
    public static function arrivals(Deal $deal): Collection
    {
        return Transaction::where('deal_id', $deal->id)->where('direction', 'in')->where('state', Transaction::MATCHED)
            ->carMoney()->orderBy('booked_at')->orderBy('id')->get();
    }

    /** Записать продажу догадкой; null — гадать нельзя (почему — `guess`). */
    public function __invoke(Deal $deal, ?User $by = null): ?Car
    {
        $by ??= Robot::user();
        $lock = 'garage-sell-statement-'.$deal->id;

        return DB::transaction(function () use ($deal, $by, $lock) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$lock]);
            $deal = $deal->fresh();
            $g = self::guess($deal);
            if (! $g['ok']) {
                $this->noteLate($deal, $by);

                return null;
            }
            $dates = $g['txs']->map(fn (Transaction $tx) => $tx->booked_at->format('d.m'))->unique()->implode(', ');
            $what = ($g['txs']->count() > 1 ? 'поступления '.$dates : 'поступление '.$dates).' от '.$g['payer'];
            try {
                // Сумму против закупочной проверили на пришедшем (`guess`) — переспрашивать «точно?» некого.
                return ($this->sold)($g['car'], array_filter(['sold_price' => $g['price'], 'sold_at' => $g['txs']->last()->booked_at, 'buyer_name' => $g['payer'],
                    'commission' => $g['reward'], 'price_ok' => true], fn ($v) => $v !== null), $by, ['guess' => $what, 'tx' => $g['txs']->pluck('id')->all()]);
            } catch (Throwable $e) {
                Log::warning('garage: продажа по выписке, сделка '.$deal->id.' — '.$e->getMessage());

                return null;
            }
        });
    }

    /** Продажа уже записана догадкой, а пришло ещё — цену не трогаем, говорим в «Истории». */
    private function noteLate(Deal $deal, User $by): void
    {
        $car = $deal->garageCar;
        $sold = $car?->isSold() ? $car->stages()->get()->last(fn ($e) => ($e->payload['state'] ?? null) === 'sold') : null;
        if (! $sold || ! isset($sold->payload['guess'])) {
            return;
        }
        $known = $sold->payload['tx'] ?? [];
        $late = self::arrivals($deal)->reject(fn (Transaction $tx) => in_array($tx->id, $known, true));
        $noted = collect($sold->payload['late'] ?? []);
        $new = $late->reject(fn (Transaction $tx) => $noted->contains($tx->id));
        if ($new->isEmpty()) {
            return;
        }
        $sold->update(['payload' => array_merge($sold->payload, ['late' => $noted->merge($new->pluck('id'))->values()->all()])]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'После продажи по догадке ещё поступление '
            .$new->map(fn (Transaction $tx) => Money::rub($tx->amount).' '.$tx->booked_at->format('d.m'))->implode(', ')
            .' — цена продажи осталась '.Money::rub((int) $car->sold_price).', проверьте']);
    }
}
