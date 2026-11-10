<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Вопрос перед черновиком письма шага (10.11.2026, начальник): «Написать письмо», «Без письма — сообщили сами», «Отмена».
 * Без письма — шаг ждёт поставщика, как после отправленного письма.
 */
class LetterAskTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    public function test_ask_then_skip_letter(): void
    {
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Владислав', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 1000000), $admin, scheme: DealScheme::Prime);
        $offer = $offer->fresh();
        $this->assertSame('unsent', $offer->position(Track::Sale)->letterStatus());

        $editor = $offer->vendorLetterUrl($offer->position(Track::Sale)->stage);
        $this->actingAs($admin)->withHeaders(['Turbo-Frame' => 'letters-frame'])
            ->get(self::CRM.'/offers/'.$offer->number.'/letter-ask?to='.urlencode($editor))->assertOk()
            ->assertSee('Написать письмо')->assertSee('Без письма')->assertSee('Отмена');
        $this->get(self::CRM.'/offers/'.$offer->number.'/letter-ask?to=/evil')->assertNotFound();

        $this->post(self::CRM.'/offers/'.$offer->number.'/letter-skip', [], ['Turbo-Frame' => 'letters-frame'])->assertOk()->assertSee('letters-close', false);
        $position = $offer->fresh()->position(Track::Sale);
        $this->assertSame('sent', $position->letterStatus());
        $this->assertFalse($position->needsUs());
        $this->assertTrue($offer->events()->where('payload->text', 'like', 'Поставщику сообщили не письмом%')->exists());
        // Второй раз — уже нечего.
        $this->post(self::CRM.'/offers/'.$offer->number.'/letter-skip')->assertSessionHasErrors('exit');
    }
}
