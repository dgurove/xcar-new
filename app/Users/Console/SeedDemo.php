<?php

namespace App\Users\Console;

use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Billing\Vat;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Offers\Actions\SyncViewers;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Демонстрационный кабинет менеджера для проверяющих платёжного сервиса: три сделки (ждёт счёта, счёт
 * выставлен и открыта ссылка на оплату, оплачена по ссылке с выплаченным вознаграждением), свои
 * покупатели, контрагент. Всё с `is_demo`: сотрудники и фоновые задачи этого не видят. Номера демо-счетов
 * живут в отдельном «году» 2000 — сквозная нумерация настоящих счетов не сдвигается; номера предложений 9001+.
 * Повторный запуск пересобирает демо-данные заново.
 */
final class SeedDemo extends Command
{
    private const YEAR = 2000;

    protected $signature = 'demo:seed {login=yookassa}';

    protected $description = 'Наполнить демонстрационный кабинет менеджера (сделки, счёт, ссылка на оплату)';

    public function handle(InvoicePdf $pdf): int
    {
        $user = User::withoutGlobalScope('demo')->where('login', $this->argument('login'))->firstOrFail();
        $user->forceFill(['role' => Role::Manager, 'is_demo' => true, 'approved_at' => $user->approved_at ?? now()])->save();
        // Дальше команда смотрит глазами демо-пользователя: демо-записи ей видны.
        Auth::setUser($user);

        DB::transaction(function () use ($user, $pdf) {
            $this->wipe($user);
            // Карта для выплат — демонстрационная, чтобы в «Деньгах» не висело «Реквизиты не указаны».
            $party = Party::create(['kind' => PartyKind::Person, 'name' => $user->name, 'phone' => $user->phone, 'card' => '2200000000000004'])->forceFill(['is_demo' => true]);
            $party->save();
            $user->forceFill(['party_id' => $party->id])->save();

            foreach ([['Алексей Смирнов', '70000000011'], ['Ольга Иванова', '70000000012']] as [$name, $phone]) {
                User::withoutGlobalScope('demo')->updateOrCreate(['phone' => $phone], [
                    'name' => $name, 'role' => Role::Buyer, 'manager_id' => $user->id, 'approved_at' => now(), 'password' => Str::random(32), 'access' => [],
                ])->forceFill(['is_demo' => true])->save();
            }

            // Сделка без счёта: подтверждение принято, счёт ещё не выставлен.
            $camry = $this->deal($user, $this->offer(9003, 'Toyota', 'Camry', 2019, 86000, 1_690_000), 1_690_000, 1_560_000, 60_000, DealState::Active);

            // Сделка в работе: счёт на разницу выставлен, менеджер открыл ссылку на оплату.
            $deal = $this->deal($user, $this->offer(9001, 'Kia', 'Rio', 2021, 48000, 1_250_000), 1_250_000, 1_100_000, 50_000, DealState::Active);
            $invoice = $this->invoice($party, $user, $deal, 1001, now()->subDay(), 100_000, 50_000);
            $pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
            PayLink::create([
                'code' => PayLink::freshCode(), 'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'payer_kind' => PayerKind::Self,
                'payer_name' => $user->name, 'payer_phone' => $user->phone, 'state' => PayLinkState::Open, 'created_by' => $user->id,
            ]);
            $this->place($camry, $deal);

            // Закрытая сделка: оплачена по ссылке, вознаграждение выплачено.
            $done = $this->deal($user, $this->offer(9002, 'Hyundai', 'Solaris', 2020, 61000, 980_000, OfferState::Delivered), 980_000, 900_000, 30_000, DealState::Done);
            $paid = $this->invoice($party, $user, $done, 1002, now()->subDays(14), 50_000, 30_000);
            $pdf->attach($paid->fresh(['charges', 'party', 'payments']));
            $payment = $this->pay($paid, now()->subDays(12), PaymentSource::Acquiring, 'По ссылке через СБП');
            PayLink::create([
                'code' => PayLink::freshCode(), 'invoice_id' => $paid->id, 'amount' => $paid->total, 'payer_kind' => PayerKind::Self, 'payer_name' => $user->name,
                'payer_phone' => $user->phone, 'state' => PayLinkState::Paid, 'payment_id' => $payment->id, 'paid_at' => $payment->paid_at, 'created_by' => $user->id,
            ]);
            $fee = Invoice::create([
                'direction' => 'owed', 'kind' => ChargeKind::AgentFee, 'party_id' => $party->id, 'deal_id' => $done->id, 'offer_id' => $done->offer_id,
                'issued_at' => now()->subDays(12)->toDateString(), 'due_at' => now()->subDays(7)->toDateString(), 'vat' => false, 'total' => 30_000, 'state' => InvoiceState::Issued,
            ])->forceFill(['is_demo' => true]);
            $fee->save();
            Charge::create(['party_id' => $party->id, 'deal_id' => $done->id, 'invoice_id' => $fee->id, 'kind' => ChargeKind::AgentFee, 'title' => 'Агентское вознаграждение', 'qty' => 1, 'unit' => 'pc', 'price' => 30_000, 'amount' => 30_000]);
            $this->pay($fee, now()->subDays(9), PaymentSource::Bank, 'Выплата вознаграждения');
        });

        $this->info('Демо-кабинет готов: '.$user->login);

        return self::SUCCESS;
    }

    /**
     * Сделки в работе стоят на этапах маршрута продажи первого же вендора, где есть «Счёт выставлен менеджеру»:
     * Camry — шагом раньше (счёт ещё не выставлен), Rio — на счёте. Без срока: часы маршрута демо не трогают.
     */
    private function place(Deal $waiting, Deal $invoiced): void
    {
        $invoice = Stage::where('name', 'Счёт выставлен менеджеру')->orderBy('id')->first();
        if (! $invoice) {
            return;
        }
        $before = Stage::where('workflow_id', $invoice->workflow_id)->whereHas('exits', fn ($e) => $e->where('to_stage_id', $invoice->id))->orderBy('id')->first();
        foreach ([[$waiting, $before ?? $invoice], [$invoiced, $invoice]] as [$deal, $stage]) {
            Position::create(['offer_id' => $deal->offer_id, 'track' => Track::Sale->value, 'stage_id' => $stage->id, 'entered_at' => now()->subDays(2), 'block_entered_at' => now()->subDays(2)]);
        }
    }

    /** Прежние демо-данные этого менеджера — долой, пользователь и его покупатели остаются. */
    private function wipe(User $user): void
    {
        $invoices = Invoice::where('is_demo', true)->pluck('id');
        PayLink::whereIn('invoice_id', $invoices)->delete();
        Payment::whereIn('invoice_id', $invoices)->delete();
        Charge::whereIn('invoice_id', $invoices)->delete();
        Invoice::whereIn('id', $invoices)->get()->each->delete();
        Position::whereIn('offer_id', Offer::where('is_demo', true)->pluck('id'))->delete();
        Deal::where('is_demo', true)->delete();
        Offer::where('is_demo', true)->get()->each->delete();
        Party::where('is_demo', true)->delete();
    }

    private function offer(int $number, string $brand, string $model, int $year, int $mileage, int $price, OfferState $state = OfferState::Sold): Offer
    {
        $b = Brand::where('name', 'ilike', $brand)->first();
        $m = $b ? CarModel::where('brand_id', $b->id)->where('name', 'ilike', $model)->first() : null;
        $offer = new Offer([
            'brand_id' => $b?->id, 'model_id' => $m?->id, 'year' => $year, 'mileage' => $mileage, 'show_vin' => false,
            'floor_price' => $price - 150_000, 'publish_price' => $price - 150_000, 'asking_price' => $price, 'prices_include_vat' => false,
            'description' => 'Демонстрационное предложение',
        ]);
        $offer->forceFill(['number' => $number, 'state' => $state, 'is_demo' => true])->save();
        app(SyncViewers::class)($offer);

        return $offer;
    }

    private function deal(User $user, Offer $offer, int $amount, int $cost, int $commission, DealState $state): Deal
    {
        $deal = new Deal(['offer_id' => $offer->id, 'buyer_id' => $user->id, 'amount' => $amount, 'cost' => $cost, 'commission' => $commission,
            'commission_mode' => CommissionMode::Payout, 'state' => $state, 'closed_at' => $state === DealState::Done ? now()->subDays(9) : null]);
        $deal->forceFill(['is_demo' => true])->save();

        return $deal;
    }

    /** Счёт на разницу двумя строками, как `IssueDealInvoice`: подбор и агентское вознаграждение. */
    private function invoice(Party $party, User $user, Deal $deal, int $number, Carbon $at, int $selection, int $fee): Invoice
    {
        $car = $deal->offer->titleWithYear();
        $invoice = new Invoice([
            'direction' => 'issued', 'year' => self::YEAR, 'number' => $number, 'kind' => ChargeKind::Selection, 'party_id' => $party->id,
            'deal_id' => $deal->id, 'offer_id' => $deal->offer_id, 'issued_at' => $at->toDateString(), 'due_at' => $at->copy()->addDays(5)->toDateString(),
            'vat' => true, 'vat_rate' => Vat::rate(), 'total' => $selection + $fee, 'paid' => 0, 'state' => InvoiceState::Issued,
        ]);
        $invoice->forceFill(['is_demo' => true])->save();
        foreach ([['Подбор ТС '.$car, $selection, ChargeKind::Selection], ['Агентское вознаграждение', $fee, ChargeKind::AgentFee]] as [$title, $price, $kind]) {
            Charge::create(['party_id' => $party->id, 'deal_id' => $deal->id, 'invoice_id' => $invoice->id, 'kind' => $kind, 'title' => $title, 'qty' => 1, 'unit' => 'pc', 'price' => $price, 'amount' => $price]);
        }

        return $invoice;
    }

    private function pay(Invoice $invoice, Carbon $at, PaymentSource $source, string $note): Payment
    {
        $payment = Payment::create(['invoice_id' => $invoice->id, 'party_id' => $invoice->party_id, 'amount' => $invoice->total, 'paid_at' => $at->toDateString(),
            'source' => $source, 'note' => $note, 'state' => PaymentState::Confirmed]);
        $invoice->update(['paid' => $invoice->total, 'state' => InvoiceState::Paid, 'paid_at' => $at->toDateString()]);

        return $payment;
    }
}
