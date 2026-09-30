<?php

namespace Tests\Feature;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Actions\SettleAcquiring;
use App\Billing\Acquiring\Checkout;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\ClaimPayment;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Bank\Actions\MatchTransaction;
use App\Billing\Bank\Transaction;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Деньги по счёту приходят одной оплатой, откуда бы о них ни узнали: повтор уведомления ЮKassa,
 * опрос и выписка не задваивают; поступление, о котором менеджер уже сообщил, подтверждает его заявку;
 * перечисление ЮKassa на расчётный счёт в счёт не ложится, а сверяется с оплатами по ссылкам суммой за вычетом
 * комиссии. Ошибка тут молчалива и дорога — поэтому тест.
 */
class MoneyArrivesOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        User::create(['name' => 'Владелец', 'phone' => '79000000001', 'role' => Role::Admin, 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'email' => 'manager@example.test', 'role' => Role::Manager, 'approved_at' => now()]);
        $party = Party::create(['kind' => PartyKind::Entrepreneur, 'name' => 'ИП Менеджер', 'inn' => '771234567890', 'phone' => '79000000002']);
        $this->invoice = app(IssueInvoice::class)($party, User::first(), 'issued', ChargeKind::Other, now()->addDays(3), lines: [['title' => 'Разница по сделке', 'price' => 100000]]);
    }

    public function test_repeated_acquiring_notice_records_one_payment(): void
    {
        $link = app(CreatePayLink::class)($this->invoice, $this->manager, 60000, PayerKind::Self);
        $attempt = AcquiringPayment::create(['link_id' => $link->id, 'provider' => 'yookassa', 'external_id' => 'p-1', 'status' => 'pending', 'amount' => 60000]);
        $paid = new Checkout('p-1', 'succeeded', 60000, 58500, 'sbp', null, 'succeeded', []);

        app(SettleAcquiring::class)($attempt, $paid);
        app(SettleAcquiring::class)($attempt->fresh(), $paid);

        $this->invoice->refresh();
        $this->assertSame(1, $this->invoice->payments()->count());
        $this->assertEquals(40000, $this->invoice->remaining());
        $this->assertSame(PayLinkState::Paid, $link->fresh()->state);
        $this->assertEquals(1500, $attempt->fresh()->fee());
    }

    public function test_link_paid_after_invoice_closed_in_part_leaves_surplus(): void
    {
        $link = app(CreatePayLink::class)($this->invoice, $this->manager, 100000, PayerKind::Self);
        $attempt = AcquiringPayment::create(['link_id' => $link->id, 'provider' => 'yookassa', 'external_id' => 'p-2', 'status' => 'pending', 'amount' => 100000]);
        app(MatchTransaction::class)($this->incoming(30000, 'Оплата по счёту № '.$this->invoice->number, '771234567890'));

        app(SettleAcquiring::class)($attempt, new Checkout('p-2', 'succeeded', 100000, 97500, 'bank_card', null, 'succeeded', []));

        $this->assertSame(InvoiceState::Paid, $this->invoice->fresh()->state);
        $this->assertEquals(30000, $attempt->fresh()->overpaid());
    }

    public function test_invoice_number_is_read_only_as_a_word(): void
    {
        $this->assertSame([[12], 2026], MatchTransaction::numbers('Оплата по счёту № 12 от 28.09.2026'));
        $this->assertSame([[9], null], MatchTransaction::numbers('опл. по счету N 9, НДС нет'));
        $this->assertSame([[], null], MatchTransaction::numbers('Взаиморасчет по договору 3'));
        $this->assertSame([[], null], MatchTransaction::numbers('Счёт-фактура № 45'));
        $this->assertSame([[], null], MatchTransaction::numbers('Перевод на р/сч 40702810340000004750'));
    }

    public function test_statement_confirms_manager_claim_instead_of_second_payment(): void
    {
        $claim = app(ClaimPayment::class)($this->invoice, $this->manager, 100000, now());
        $tx = $this->incoming(100000, 'Оплата по счёту № '.$this->invoice->number.' от '.$this->invoice->issued_at->format('d.m.Y'), '771234567890');

        app(MatchTransaction::class)($tx);
        app(MatchTransaction::class)($tx->fresh());

        $this->invoice->refresh();
        $this->assertSame(Transaction::MATCHED, $tx->fresh()->state);
        $this->assertSame(InvoiceState::Paid, $this->invoice->state);
        $this->assertSame(1, $this->invoice->allPayments()->count());
        $this->assertSame(PaymentState::Confirmed, $claim->fresh()->state);
    }

    public function test_acquiring_payout_reconciles_by_income_and_does_not_touch_invoices(): void
    {
        $link = app(CreatePayLink::class)($this->invoice, $this->manager, 60000, PayerKind::Self);
        $first = $this->succeeded($link->id, 'p-3', 60000, 58500, now()->subDays(2));
        $second = $this->succeeded($link->id, 'p-4', 1000, 975, now()->subDay());
        $payout = $this->incoming(59475, 'Перечисление по договору', config('xcar.yookassa.payout_inn'));
        $foreign = $this->incoming(100000, 'Возврат займа', '7700000000');

        app(MatchTransaction::class)($payout);
        app(MatchTransaction::class)($foreign);

        $this->assertSame(Transaction::MATCHED, $payout->fresh()->state);
        $this->assertSame($payout->id, $first->fresh()->payout_tx_id);
        $this->assertSame($payout->id, $second->fresh()->payout_tx_id);
        $this->assertStringContainsString('комиссия 1', $payout->fresh()->note);
        $this->assertSame(Transaction::UNMATCHED, $foreign->fresh()->state);
        $this->assertEquals(0, $this->invoice->fresh()->paid);
    }

    public function test_acquiring_payout_that_does_not_add_up_waits_for_a_person(): void
    {
        $link = app(CreatePayLink::class)($this->invoice, $this->manager, 60000, PayerKind::Self);
        $attempt = $this->succeeded($link->id, 'p-5', 60000, 58500, now()->subDay());
        $payout = $this->incoming(50000, 'Перечисление по договору', config('xcar.yookassa.payout_inn'));

        app(MatchTransaction::class)($payout);

        $this->assertSame(Transaction::UNMATCHED, $payout->fresh()->state);
        $this->assertNull($attempt->fresh()->payout_tx_id);
        $this->assertEquals(0, $this->invoice->fresh()->paid);
    }

    private function succeeded(int $link, string $id, float $amount, float $income, $at): AcquiringPayment
    {
        $attempt = AcquiringPayment::create(['link_id' => $link, 'provider' => 'yookassa', 'external_id' => $id, 'status' => 'succeeded', 'amount' => $amount,
            'income_amount' => $income, 'payload' => ['captured_at' => $at->toIso8601String()]]);
        $attempt->forceFill(['created_at' => $at])->save();

        return $attempt;
    }

    private function incoming(float $amount, string $purpose, ?string $inn): Transaction
    {
        return Transaction::create([
            'external_id' => uniqid('t-'), 'account' => '40702810340000004750', 'booked_at' => now()->toDateString(), 'direction' => 'in',
            'amount' => $amount, 'counterparty' => 'Плательщик', 'counterparty_inn' => $inn, 'purpose' => $purpose, 'state' => Transaction::UNMATCHED,
        ]);
    }
}
