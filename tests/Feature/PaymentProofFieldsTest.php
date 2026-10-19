<?php

namespace Tests\Feature;

use App\Billing\Actions\ClaimPayment;
use App\Billing\PaymentSource;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «№ и дата платёжки» — только там, где платёжка есть (19.10.2026, владелец: «постоянно всплывает при оплате наличкой»):
 * наличными ни форма оплаты, ни шаг её не спрашивают; на «Договор получен» нашей оплаты ещё нет — платёжку вписывают на
 * «Поставщику оплачено»; менеджер отдал наличными — так и написано в ответе и истории.
 */
class PaymentProofFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    private Deal $deal;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Кузнецова Екатерина', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = $this->vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, $this->stage('Приём подтверждений'));
        $this->deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, CommissionMode::Withheld, scheme: DealScheme::Prime);
        $this->offer = $offer->fresh();
    }

    private function stage(string $name): Stage
    {
        return Stage::whereHas('workflow', fn ($q) => $q->where('vendor_id', $this->vendor->id))->where('name', $name)->with('exits.to', 'workflow')->firstOrFail();
    }

    public function test_cash_claim_passes_step_as_cash(): void
    {
        app(TakeExit::class)($this->offer, $this->offer->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал'), Actor::Manager, $this->manager);
        app(SaveDealContract::class)(DealContract::for($this->deal->fresh()), ['buyer_id' => 'me'], $this->manager);
        $this->passportFor($this->manager);
        $invoice = $this->deal->fresh()->issuedInvoices()->sole();

        app(ClaimPayment::class)($invoice, $this->manager, $invoice->remaining(), now(), null, null, PaymentSource::Cash);

        $this->assertSame('Проверка оплаты', $this->offer->fresh()->stage()->name);
        $answer = Requirement::where('deal_id', $this->deal->id)->whereNotNull('done_at')->latest('id')->first()->answer;
        $this->assertSame('Платёжное поручение приложено', $answer['exit']);
        $this->assertSame('Отдал наличными', $answer['label']);
        $history = $this->offer->events()->whereIn('type', [OfferEventType::RequirementAnswered, OfferEventType::StageEntered])->get()->map->text();
        $this->assertContains('Менеджер: «Отдал наличными»', $history);
        $this->assertContains('Этап: Проверка оплаты («Отдал наличными»)', $history);
    }

    public function test_payment_proof_asked_on_leaving_payment_stage(): void
    {
        // Подписание — как было: приём и трек на входе. «Договор получен» нашей платёжки не спрашивает.
        $this->assertSame([], $this->stage('Подписанный договор')->exits->firstWhere('label', 'Договор получен')->staffFields());
        $this->assertTrue($this->stage('Оплата поставщику')->paysOut());
        $this->assertFalse($this->stage('Подписанный договор')->paysOut());

        $paid = $this->stage('Оплата поставщику')->exits->firstWhere('label', 'Поставщику оплачено');
        $fields = $paid->staffFields();
        $this->assertSame(['Дата оплаты', 'Номер платёжки'], array_column($fields, 'label'));
        $this->assertSame([false, false], array_column($fields, 'required'));
        $this->assertSame('date', $fields[0]['type']);

        app(EnterStage::class)($this->offer, $this->stage('Оплата поставщику'), $this->admin);
        $keys = array_column($fields, 'key');
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/exit/'.$paid->id, ['fields' => [$keys[0] => '2026-10-19', $keys[1] => '512']])->assertRedirect();

        $this->assertNull(Position::where('offer_id', $this->offer->id)->where('track', Track::Sale)->value('payload'));
        $entered = $this->offer->events()->where('type', OfferEventType::StageEntered)->latest('id')->first();
        $this->assertSame(['Дата оплаты' => '19 октября 2026', 'Номер платёжки' => '512'], $entered->payload['fields']);
        $this->assertStringEndsWith('(«Поставщику оплачено»), дата оплаты 19 октября 2026, номер платёжки 512', $entered->text());
    }

    public function test_pay_form_keeps_slip_only_for_bank(): void
    {
        $invoice = $this->primeInvoice();
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/work/money/invoices/'.$invoice->id.'/payments', [
            'source' => 'cash', 'amount' => '1000', 'paid_at' => now()->toDateString(), 'ref' => '777', 'slip' => UploadedFile::fake()->create('pp.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $cash = $invoice->payments()->latest('id')->first();
        $this->assertSame(PaymentSource::Cash, $cash->source);
        $this->assertNull($cash->ref);
        $this->assertNull($cash->slip());

        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/work/money/invoices/'.$invoice->id.'/payments', ['source' => 'acquiring', 'amount' => '1000', 'ref' => 'op-1'])->assertSessionHasNoErrors();
        $this->assertSame('op-1', $invoice->payments()->latest('id')->first()->ref);

        $html = (string) $this->withViewErrors([])->blade('<x-billing.pay-form :invoice="$i" action="/x" sheet/>', ['i' => $invoice->fresh()]);
        $this->assertStringContainsString('data-reveal-key="cash"', $html);
        $this->assertSame(1, substr_count($html, '№ платёжки'));
        $this->assertStringContainsString('№ операции', $html);
    }

    private function primeInvoice(): \App\Billing\Invoice
    {
        app(TakeExit::class)($this->offer, $this->offer->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал'), Actor::Manager, $this->manager);
        app(SaveDealContract::class)(DealContract::for($this->deal->fresh()), ['buyer_id' => 'me'], $this->manager);
        $this->passportFor($this->manager);

        return $this->deal->fresh()->issuedInvoices()->sole();
    }
}
