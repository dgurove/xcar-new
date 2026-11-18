<?php

namespace App\Offers\Console;

use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\Robot;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Разово (18.11.2026, начальник: «вознаграждения менеджерам мы никогда не выплачиваем»): выплаты менеджерам (`agent_fee`,
 * `owed`) аннулируются — и «оплаченные» (владелец: «мисклики, в оплату услуг подбора шли»); счета идущих сделок, что стоят
 * не так, как велит новое правило (одной строкой на цену минус вознаграждение, без зачёта), перевыставляются тем же
 * `SyncDealInvoices`: неоплаченные — заново (отправленные менеджеру встают в «Отправить счёт»), оплаченные — история.
 * Договор, уже отданный менеджеру с датой, не трогаем вместе со счётом. Без `--apply` — то же внутри транзакции с откатом: список, что изменится, ничего не меняя. Уведомлений нет ни там, ни там.
 */
class NoPayoutsCommand extends Command
{
    protected $signature = 'deals:no-payouts {--apply}';

    protected $description = 'Аннулировать выплаты менеджерам и перевыставить счета по новому правилу';

    public function handle(VoidInvoice $void, VoidPayment $voidPayment, SyncDealInvoices $sync): int
    {
        $apply = (bool) $this->option('apply');
        $by = Robot::user();
        Notification::fake();
        DB::beginTransaction();
        try {
            $fees = Invoice::where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', '!=', 'void')->where('is_demo', false)->with(['payments', 'party', 'deal.offer'])->get();
            foreach ($fees as $fee) {
                $this->line(sprintf('Выплата %s по № %s: %s, %s — аннулируем', $fee->party?->name, $fee->deal?->offer?->number ?? '—', Money::rub((float) $fee->total), $fee->state->label()));
                foreach ($fee->payments->whereNull('voided_at') as $p) {
                    $voidPayment($p, $by, 'Вознаграждение менеджеру мы не выплачиваем');
                }
                $void($fee->fresh(), $by, 'Вознаграждение менеджеру мы не выплачиваем');
            }

            $changed = 0;
            $deals = Deal::where('is_demo', false)->active()->whereNotNull('buyer_id')->with(['offer', 'buyer', 'contract'])->orderBy('id')->get()
                ->reject(fn (Deal $d) => $d->isParallel())->filter(fn (Deal $d) => self::affected($d));
            foreach ($deals as $deal) {
                $before = $this->snapshot($deal);
                $price = $deal->contract?->price;
                $sync($deal, $by);
                $after = $this->snapshot($deal->fresh());
                $priceNow = $deal->fresh()->contract?->price;
                if ($before === $after && $price === $priceNow) {
                    continue;
                }
                $changed++;
                $this->line(sprintf('№ %s %s (%s, вознаграждение %s):', $deal->offer->number, $deal->buyer?->name, $deal->schemeOf()->value, Money::rub((int) $deal->commission)));
                foreach (array_diff_key($before, $after) as $line) {
                    $this->line('   было:  '.$line);
                }
                foreach (array_diff_key($after, $before) as $line) {
                    $this->line('   стало: '.$line);
                }
                if ($price !== $priceNow) {
                    $this->line('   договор: '.Money::rub((int) $price).' → '.Money::rub((int) $priceNow));
                }
            }
            $this->info(($apply ? 'Сделано' : 'Изменится').": выплат {$fees->count()}, сделок {$changed}");
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $apply || $this->comment('Только показал: запустите с --apply');

        return self::SUCCESS;
    }

    /**
     * Сделку задело новое правило: в неоплаченном счёте строка «Агентское вознаграждение» или зачёт вознаграждения; у ПРАЙМ /
     * Р.О.С. неоплаченный счёт за ТС или договор (не вписанный руками) — не на цену минус вознаграждение (был режим
     * «выплачиваем»). Остальные не трогаем: их счета ставятся своим ходом.
     */
    private static function affected(Deal $deal): bool
    {
        // Договор уже у менеджера (отправлен и датирован) — бумага на руках, сумму в ней и счёт под неё не переписываем.
        if ($deal->contract?->shown_at && $deal->contract->signed_at) {
            return false;
        }
        $open = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', 'issued')->with(['charges', 'payments'])->get()
            ->reject(fn (Invoice $i) => $i->payments->contains(fn ($p) => ! $p->voided_at && ! $p->source->isSoft()));
        if ($open->contains(fn (Invoice $i) => $i->charges->whereNull('voided_at')->contains('kind', ChargeKind::AgentFee)
            || $i->payments->contains(fn ($p) => ! $p->voided_at && $p->source === \App\Billing\PaymentSource::Offset))) {
            return true;
        }
        if (! $deal->schemeOf()->paysCar() || $deal->isGarage() || $deal->amount === null || ! $deal->commission) {
            return false;
        }
        $want = (int) \App\Offers\DealContract::priceFor($deal);
        $contract = $deal->contract;
        if ($contract && $contract->price !== null && $contract->price !== $want) {
            return true;
        }

        return $open->contains(fn (Invoice $i) => $i->kind === ChargeKind::Sale && abs((float) $i->charges->whereNull('voided_at')->sum(fn ($c) => $c->price * $c->qty) - $want) > 0.5);
    }

    /** @return array<int, string> живые наши счета сделки: id → «№ 166 продажа 1 000 000 ₽, отправлен, оплачено 0» */
    private function snapshot(Deal $deal): array
    {
        return Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', 'void')->with('charges')->orderBy('id')->get()
            ->mapWithKeys(fn (Invoice $i) => [$i->id => sprintf('%s %s %s%s, оплачено %s, строки: %s', $i->label() ?: 'без номера', $i->kind->label(), Money::rub((float) $i->total),
                $i->shown_at ? ', отправлен' : '', Money::rub((float) $i->paid), $i->charges->whereNull('voided_at')->map(fn ($c) => $c->title.' '.Money::rub((float) $c->price))->implode('; '))])
            ->all();
    }
}
