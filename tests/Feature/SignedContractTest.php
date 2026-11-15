<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Подписанный договор вне системы (15.11.2026, владелец: «ОБЯЗАТЕЛЬНО возможность и у менеджера в сделке, и у сотрудников
 * в CRM прикрепить подписанный договор, сразу, без покупателя»; 735163/2026 на «Оплате поставщику» — приложить было
 * некуда). Ошибка здесь молчаливая: договор не приложить, чужой видит или кладёт файл, шаг договора снова просит то, что есть.
 */
class SignedContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_crm_attach_signed_contract_without_buyer(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $other = User::create(['name' => 'Чужой Менеджер', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Модератор', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        $stages = Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id);
        app(EnterStage::class)($offer, (clone $stages)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, CommissionMode::Payout, scheme: DealScheme::Prime);
        $this->assertNull($deal->fresh()->contract?->buyer_user_id, 'покупателя нет');

        $site = fn (string $p) => Surface::Site->url($p);
        $crm = fn (string $p) => Surface::Crm->url($p);
        $pdf = fn () => UploadedFile::fake()->create('Договор подписанный.pdf', 120, 'application/pdf');

        // Чужой менеджер — не его сделка.
        $this->actingAs($other)->post($site('/deals/'.$deal->id.'/signed'), ['files' => [$pdf()]])->assertNotFound();
        $this->assertCount(0, $deal->fresh()->getMedia('signed_contract'));

        // Менеджер сделки — сразу, без покупателя: файл у сделки, договор подписан, покупателя больше не просим.
        $this->actingAs($manager)->post($site('/deals/'.$deal->id.'/signed'), ['files' => [$pdf()]])->assertRedirect();
        $deal = $deal->fresh();
        $this->assertCount(1, $deal->getMedia('signed_contract'));
        $this->assertTrue($deal->contractSigned());
        $this->assertNull($deal->buyer_asked_at);

        // Модератор в CRM — ещё страница; файл открывается менеджеру и модератору, чужому — нет.
        $this->actingAs($moderator)->post($crm('/work/deals/'.$deal->id.'/signed'), ['files' => [$pdf()]])->assertRedirect();
        $media = $deal->fresh()->getMedia('signed_contract');
        $this->assertCount(2, $media);
        $this->actingAs($manager)->get($site('/files/'.$media[0]->id))->assertOk();
        $this->actingAs($moderator)->get($crm('/files/'.$media[0]->id))->assertOk();
        $this->actingAs($other)->get($site('/files/'.$media[0]->id))->assertNotFound();

        // Дошла до шага «Подписанный договор» — файлы уже в его просьбе.
        app(EnterStage::class)($offer->fresh(), (clone $stages)->where('name', 'Подписанный договор')->firstOrFail());
        $req = $deal->fresh()->openRequirement;
        $this->assertNotNull($req);
        $this->assertCount(2, $req->getMedia('files'));

        // Убрать файл — × у строки.
        $this->actingAs($manager)->delete($site('/deals/'.$deal->id.'/signed/'.$media[1]->id))->assertRedirect();
        $this->assertCount(1, $deal->fresh()->getMedia('signed_contract'));
    }
}
