<?php

namespace Tests\Feature;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Actions\RefundAcquiring;
use App\Billing\Acquiring\Actions\StartCheckout;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Events\OnlinePaymentTrouble;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentSource;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ЮKassa на границе с нами: деньги не теряются, когда ответ провайдера потерялся, сервер лежал или уведомление пришло
 * раньше нашей записи; возврат и переплата видны. Провайдер — `Http::fake`, ни одного настоящего запроса. Ошибка тут
 * молчалива и стоит денег — поэтому тест.
 */
class AcquiringFlowTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.yookassa.test/v3';

    private User $manager;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config(['xcar.yookassa.url' => self::API, 'xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test', 'xcar.yookassa.receipt' => true]);
        User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'email' => 'manager@example.test', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $party = Party::create(['kind' => PartyKind::Entrepreneur, 'name' => 'ИП Менеджер', 'inn' => '771234567890', 'phone' => '79000000002']);
        $this->invoice = app(IssueInvoice::class)($party, User::first(), 'issued', ChargeKind::Other, now()->addDays(3), lines: [['title' => 'Разница по сделке', 'price' => 100000]]);
    }

    private function link(float $amount = 100000): PayLink
    {
        return app(CreatePayLink::class)($this->invoice, $this->manager, $amount, PayerKind::Self);
    }

    private static function payment(string $id, string $status, float $amount, ?int $link = null, array $extra = []): array
    {
        return [
            'id' => $id, 'status' => $status, 'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
            'confirmation' => ['type' => 'redirect', 'confirmation_url' => 'https://yoomoney.test/checkout/'.$id],
            'metadata' => $link ? ['link' => (string) $link] : [], ...$extra,
        ];
    }

    public function test_failed_create_is_retried_with_the_same_key_and_gives_one_attempt(): void
    {
        $link = $this->link();
        $keys = [];
        Http::fake(function (Request $r) use (&$keys, $link) {
            $keys[] = $r->header('Idempotence-Key')[0] ?? null;

            return count($keys) === 1 ? Http::response(['type' => 'error'], 500) : Http::response(self::payment('p-1', 'pending', 100000, $link->id));
        });

        $url = app(StartCheckout::class)($link);
        // Второе «Оплатить» после обрыва страницы: ЮKassa по тому же ключу вернула бы тот же платёж — строка одна.
        app(StartCheckout::class)($link->fresh());

        $this->assertSame('https://yoomoney.test/checkout/p-1', $url);
        $this->assertSame($keys[0], $keys[1], 'повтор идёт тем же ключом');
        $this->assertSame(1, AcquiringPayment::count());
    }

    public function test_payer_without_email_is_asked_on_the_pay_page(): void
    {
        $link = app(CreatePayLink::class)($this->invoice, $this->manager, 100000, PayerKind::Other, null, 'Покупатель', null, null);
        Http::fake(fn () => Http::response(self::payment('p-2', 'pending', 100000, $link->id)));

        $this->post('/pay/'.$link->code)->assertSessionHasErrors('email');
        $this->post('/pay/'.$link->code, ['name' => 'Иван Петров', 'email' => 'ivan@example.test'])->assertRedirect('https://yoomoney.test/checkout/p-2');

        $this->assertSame('ivan@example.test', $link->fresh()->payer_email);
        Http::assertSent(fn (Request $r) => data_get($r->data(), 'receipt.customer.email') === 'ivan@example.test');
    }

    public function test_notice_about_unknown_payment_records_it_by_link(): void
    {
        $link = $this->link();
        Http::fake([self::API.'/payments/p-lost' => Http::response(self::payment('p-lost', 'succeeded', 100000, $link->id, ['income_amount' => ['value' => '96500.00', 'currency' => 'RUB'], 'payment_method' => ['type' => 'sbp']]))]);

        $this->postJson('/hooks/yookassa', ['event' => 'payment.succeeded', 'object' => ['id' => 'p-lost']])->assertNoContent();

        $this->assertSame(InvoiceState::Paid, $this->invoice->fresh()->state);
        $this->assertSame(PayLinkState::Paid, $link->fresh()->state);
        $this->assertSame(1, AcquiringPayment::where('external_id', 'p-lost')->count());
    }

    public function test_old_pending_is_not_cancelled_without_asking_the_provider(): void
    {
        $link = $this->link();
        $attempt = AcquiringPayment::create(['link_id' => $link->id, 'provider' => 'yookassa', 'external_id' => 'p-old', 'status' => 'pending', 'amount' => 100000]);
        AcquiringPayment::whereKey($attempt->id)->update(['created_at' => now()->subDays(2), 'checked_at' => now()->subHours(2)]);
        Http::fake([self::API.'/payments/p-old' => Http::response(self::payment('p-old', 'succeeded', 100000, $link->id))]);

        $this->artisan('acquiring:sync')->assertSuccessful();

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame(InvoiceState::Paid, $this->invoice->fresh()->state);
    }

    public function test_declined_payment_keeps_the_reason_and_tells_the_manager(): void
    {
        Event::fake([OnlinePaymentTrouble::class]);
        $link = $this->link();
        $attempt = AcquiringPayment::create(['link_id' => $link->id, 'provider' => 'yookassa', 'external_id' => 'p-no', 'status' => 'pending', 'amount' => 100000]);
        Http::fake([self::API.'/payments/p-no' => Http::response(self::payment('p-no', 'canceled', 100000, $link->id, ['cancellation_details' => ['party' => 'payment_network', 'reason' => 'insufficient_funds']]))]);

        $this->get('/pay/'.$link->code.'?back=1')->assertOk()->assertSee('Оплата не прошла')->assertSee('Недостаточно денег');

        $this->assertSame('insufficient_funds', $attempt->fresh()->cancel_reason);
        Event::assertDispatched(OnlinePaymentTrouble::class, fn ($e) => $e->what === 'declined');
        $this->assertTrue($link->fresh()->isOpen(), 'ссылка работает дальше');
    }

    public function test_payment_after_invoice_was_paid_otherwise_is_an_overpayment(): void
    {
        Event::fake([OnlinePaymentTrouble::class]);
        $link = $this->link();
        $attempt = AcquiringPayment::create(['link_id' => $link->id, 'provider' => 'yookassa', 'external_id' => 'p-late', 'status' => 'pending', 'amount' => 100000]);
        app(RecordPayment::class)($this->invoice->fresh(), User::first(), 100000, now(), PaymentSource::Bank);
        Http::fake([self::API.'/payments/p-late' => Http::response(self::payment('p-late', 'succeeded', 100000, $link->id))]);

        $this->artisan('acquiring:sync')->assertSuccessful();

        $this->assertEquals(100000, $attempt->fresh()->overpaid());
        Event::assertDispatched(OnlinePaymentTrouble::class, fn ($e) => $e->what === 'overpaid');
    }

    public function test_refund_status_comes_from_the_provider(): void
    {
        $link = $this->link();
        Http::fake([
            self::API.'/payments/p-r' => Http::response(self::payment('p-r', 'succeeded', 100000, $link->id)),
            self::API.'/refunds' => Http::response(['id' => 'r-1', 'status' => 'pending', 'payment_id' => 'p-r']),
            self::API.'/refunds/r-1' => Http::response(['id' => 'r-1', 'status' => 'succeeded', 'payment_id' => 'p-r']),
        ]);
        $this->postJson('/hooks/yookassa', ['event' => 'payment.succeeded', 'object' => ['id' => 'p-r']]);
        $attempt = AcquiringPayment::where('external_id', 'p-r')->first();

        app(RefundAcquiring::class)($attempt, User::first());
        $this->assertSame('pending', $attempt->fresh()->refund_status);
        $this->assertSame(InvoiceState::Issued, $this->invoice->fresh()->state, 'деньги вернули — счёт снова ждёт');

        $this->postJson('/hooks/yookassa', ['event' => 'refund.succeeded', 'object' => ['id' => 'r-1']])->assertNoContent();
        $this->assertSame('succeeded', $attempt->fresh()->refund_status);
    }
}
