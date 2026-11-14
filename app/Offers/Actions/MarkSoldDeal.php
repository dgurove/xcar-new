<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueAgentFee;
use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Logistics\Actions\Cancel;
use App\Logistics\Transport;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\FastForward;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\Actions\MarkSold;
use App\Park\VehicleState;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Уже продана…» (14.11.2026, владелец: «очень много тачек в системе, которые уже проданы давно, а мы не понимаем, как их в
 * завершённые перевести», «пиздец неудобно все шаги прокликивать»): шаги сделки проходятся одним нажатием — главной дорогой
 * (`FastForward`) до закрытия или до первого шага, который правда не сделан:
 * — договор «Ещё нет» — встаёт на шаге договора (`Stage::aboutContract`), дальше обычным ходом, данные покупателя нужны;
 * — поставщику «Ещё нет» — на шаге нашей оплаты поставщику (`Stage::paysOut`).
 * Договор «Подписан» — отметка `contract_signed_at` (`MarkContractSigned::mark`). Деньги менеджера «Да» — каждый наш
 * неоплаченный счёт сделки оплачен (`RecordPayment`, пока сделка идёт — вознаграждение встанет само), «Нет» — счета висят
 * («Оплаты → Нам должны»). Закрытие: вывоз, что ещё не начали, отменён («Вывоз не нужен»), стоит на нашей парковке —
 * выдача (`MarkSold`), иначе ТС у покупателя; сделка закрыта датой продажи, без уведомлений (давно проданная).
 */
final class MarkSoldDeal
{
    public function __construct(private PlaceOnStage $place, private RecordPayment $pay, private AgreeDeal $agree, private IssueAgentFee $fee,
        private MarkSold $markSold, private SetCarPlace $carPlace, private Cancel $cancel) {}

    public static function allows(?Deal $deal, ?User $by = null): bool
    {
        return $deal?->isActive() && ! $deal->isAuction() && ! $deal->isParallel() && $deal->offer?->state === OfferState::Sold
            && (! $by || $by->canManageCrm());
    }

    /** Куда встанет сделка при ответах — для шторки и самого действия. */
    public static function run(Deal $deal, bool $signed, bool $supplierPaid): ?FastForward
    {
        $stage = $deal->offer?->stage()?->loadMissing('workflow');
        if (! $stage) {
            return null;
        }
        $stop = fn (Stage $s) => (! $signed && $s->aboutContract()) || (! $supplierPaid && $s->paysOut());

        return $stop($stage) ? FastForward::here($stage) : FastForward::from($deal, $stage, $stop);
    }

    /** Наши неоплаченные счета менеджеру по сделке (вознаграждение вендору — не его). */
    public static function unpaid(Deal $deal): Collection
    {
        return $deal->issuedInvoices()->where('state', InvoiceState::Issued)->where('kind', '!=', ChargeKind::Reward)->get()
            ->filter(fn (Invoice $i) => $i->remaining() > 0.005)->values();
    }

    /** Впереди есть наша оплата поставщику, а её ещё не отметили — вопрос «Поставщику оплачено». */
    public static function asksSupplier(Deal $deal): bool
    {
        return ! $deal->supplier_paid_at && (self::run($deal, true, true)?->passesSupplierPayout() ?? false);
    }

    public function __invoke(Deal $deal, User $by, bool $signed, ?bool $managerPaid, ?bool $supplierPaid, Carbon $soldOn): Deal
    {
        $at = $soldOn->isToday() ? now() : $soldOn->copy()->setTime(12, 0);

        return DB::transaction(function () use ($deal, $by, $signed, $managerPaid, $supplierPaid, $at) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! self::allows($deal, $by)) {
                throw ValidationException::withMessages(['deal' => 'Отметить проданной эту сделку нельзя']);
            }
            $offer = Offer::findOrFail($deal->offer_id);
            $deal->setRelation('offer', $offer);
            $stage = $offer->stage();
            $run = self::run($deal, $signed, $supplierPaid !== false);
            if (! $stage || ! $run?->stop) {
                throw ValidationException::withMessages(['deal' => 'Маршрут сделки дальше не идёт — проведите шаг вручную']);
            }
            $closes = $run->closes();
            // Продана и договор подписан — ТС у покупателя, даже если сделка ещё ждёт нашей оплаты поставщику.
            $handed = $closes || $signed;
            $open = $handed ? Transport::openFor($offer) : null;
            if ($open && (! $open->isPickup() || $open->taken())) {
                throw ValidationException::withMessages(['deal' => 'ТС везут — сначала закройте вывоз']);
            }

            // Счета встают при согласовании — прыжок мимо него их бы не поставил (`EnterStage` ставит только по пути).
            ($this->agree)($deal, $by);
            $deal->refresh()->setRelation('offer', $offer);
            $bills = self::unpaid($deal);
            // Снимок для «Вернуть сделку в работу» (`ReopenDeal`): что сделала именно она, то и откатывается.
            $snap = ['deal' => $deal->id, 'from' => $stage->id, 'place' => $offer->car_place?->value, 'payments' => []];
            // Долг держит только выставленный счёт («Нам должны» — по счетам, `DealDebt`): без него «долг» потерялся бы молча.
            if ($managerPaid === false && $bills->isEmpty()) {
                throw ValidationException::withMessages(['deal' => 'Счёта менеджеру по сделке нет — долгу не на чем висеть: выставьте счёт в «Деньгах» сделки или ответьте «Да»']);
            }
            if ($managerPaid) {
                foreach ($bills as $bill) {
                    $snap['payments'][] = ($this->pay)($bill, $by, $bill->remaining(), $at, PaymentSource::Bank, note: 'Уже продана: оплачено вне системы')->id;
                }
                if ($deal->refresh()->fullyPaid()) {
                    ($this->fee)($deal->setRelation('offer', $offer), $by);
                }
                // Счёта нет (ПРАЙМ без покупателя), а «заплатил» сказал человек — «Продано» его датой, руками: оплаты, по которой
                // `StampDealMoney` её бы поставил, нет.
                if ($bills->isEmpty() && DealDates::soldColumn($deal) === 'paid_in_at' && ! $deal->paid_in_at) {
                    $deal->forceFill(['paid_in_at' => $at, 'dates_by_hand' => array_values(array_unique([...($deal->dates_by_hand ?? []), 'paid_in_at']))])->save();
                }
            }
            $stamps = array_filter([
                'supplier_paid_at' => ! $deal->supplier_paid_at && $run->passesSupplierPayout() ? $at : null,
                'prepaid_at' => ! $deal->prepaid_at && $run->passesPayment() ? $at : null,
            ]);
            $stamps && $deal->forceFill($stamps)->save();
            $snap['stamps'] = array_keys($stamps);
            if ($signed && ! $deal->contractSigned()) {
                MarkContractSigned::mark($deal, $by, $at);
                $snap['contract'] = true;
            }

            if ($handed) {
                if ($open) {
                    $snap['transport'] = ['id' => $open->id, 'state' => $open->state->value];
                    ($this->cancel)($open, $by, 'Уже продана: вывоз не нужен', system: true);
                }
                $vehicle = $offer->parkVehicle()->first();
                if ($vehicle && in_array($vehicle->state, [VehicleState::Stored, VehicleState::Expected], true)) {
                    ($this->markSold)($vehicle, $by, $at, $deal->buyer?->name, null);
                    $snap['vehicle'] = $vehicle->id;
                } else {
                    ($this->carPlace)($offer->fresh(), CarPlace::Buyer, $by);
                }
            }

            $offer->log(OfferEventType::Note, $by, ['text' => self::summary($signed, $managerPaid, $bills, $run, $at), 'sold' => $snap]);
            Requirement::closeOpen($deal, $stage->id, ['closed_by' => 'staff', 'by' => $by->id, 'exit' => 'Уже продана']);
            if ($run->stop->id !== $stage->id) {
                ($this->place)($offer->fresh(), $run->stop, $by, payload: ['label' => 'Уже продана', ...($closes ? ['quiet' => true] : [])]);
            }
            if ($closes) {
                $deal->refresh()->forceFill(['closed_at' => $at])->save();
                $offer->positions()->where('track', Track::Sale->value)->update(['deadline_at' => null, 'waits_for' => null]);
            }

            return $deal->fresh();
        });
    }

    /** «Уже продана 12.11: договор подписан, менеджер заплатил 120 000 ₽, поставщику оплачено — сделка закрыта». */
    private static function summary(bool $signed, ?bool $managerPaid, Collection $bills, FastForward $run, Carbon $at): string
    {
        $sum = (int) round($bills->sum(fn (Invoice $i) => $i->remaining()));
        $parts = array_filter([
            $signed ? 'договор подписан' : 'договор ещё не подписан',
            $managerPaid === null || ! $sum ? null : ($managerPaid ? 'менеджер заплатил '.Money::rub($sum) : 'долг менеджера '.Money::rub($sum)),
            $run->passesSupplierPayout() ? 'поставщику оплачено' : null,
        ]);

        return 'Уже продана '.$at->format('d.m').': '.implode(', ', $parts).' — '.($run->closes() ? 'сделка закрыта' : 'встала на «'.$run->stop->name.'»');
    }
}
