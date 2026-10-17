<?php

namespace Tests\Feature;

use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Actions\AcceptInvite;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «+ Новый …» в шторке выбора человека (18.10.2026): кто кого заводит на месте — молчаливая ошибка дорогая (менеджер
 * завёл бы логиста, логист — покупателя чужому менеджеру); ссылка из сделки ставит пришедшего покупателем по ДКП сам.
 */
class PeoplePickTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_adds_whom_and_deal_link_binds_buyer(): void
    {
        Notification::fake();
        $manager = User::create(['name' => 'Иван Петров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $tanya = User::create(['name' => 'Татьяна Логист', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $logistics = 'http://'.config('xcar.logistics_host');
        $site = rtrim(config('app.url'), '/');

        // Менеджер — своего покупателя, с паролем и строкой для списка.
        $r = $this->actingAs($manager)->postJson($site.'/people', ['kind' => 'buyer', '_person' => ['first_name' => 'Анна', 'person_phone' => '8 916 111-22-33']])
            ->assertOk()->assertJsonStructure(['id', 'name', 'html', 'credentials' => ['phone', 'password', 'url']]);
        $this->assertSame($manager->id, User::find($r->json('id'))->manager_id);
        // Не своё — нельзя.
        $this->actingAs($manager)->postJson($site.'/people', ['kind' => 'logist', '_person' => ['first_name' => 'Х', 'person_phone' => '79160000001']])->assertForbidden();
        $this->actingAs($tanya)->postJson($site.'/people', ['kind' => 'buyer', '_person' => ['first_name' => 'Х', 'person_phone' => '79160000002']])->assertForbidden();
        // Ошибки — по полям, как в форме.
        $this->actingAs($manager)->postJson($site.'/people', ['kind' => 'buyer', '_person' => ['first_name' => '', 'person_phone' => '+7 916 111-22-33']])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name']);

        // Логист в логистике — исполнителя и заказчика.
        $c = $this->actingAs($tanya)->postJson($logistics.'/people', ['kind' => 'contractor', '_person' => ['first_name' => 'Пётр', 'person_phone' => '79171112233', 'logistics_city' => 'Самара']])->assertOk();
        $this->assertSame($tanya->id, User::find($c->json('id'))->logist_id);
        $this->actingAs($tanya)->postJson($logistics.'/people', ['kind' => 'client', '_person' => ['name' => 'АвтоТранс', 'address' => 'Самара']])
            ->assertOk()->assertJson(['name' => 'АвтоТранс', 'address' => 'Самара']);

        // Ссылка из сделки: пришёл — покупатель по ДКП этой сделки.
        $offer = Offer::create(['claim_ref' => 'P-1/26', 'floor_price' => 500000]);
        $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 500000, 'scheme' => DealScheme::OwnerDkp]);
        $link = $this->actingAs($manager)->postJson($site.'/people', ['kind' => 'buyer', 'mode' => 'link', 'deal' => $deal->id])->assertOk()->json('link.url');
        $invite = Invite::where('code', basename($link))->sole();
        $this->assertSame($deal->id, $invite->deal_id);
        $buyer = app(AcceptInvite::class)($invite, ['first_name' => 'Ольга', 'last_name' => 'Ким', 'login' => null, 'password' => 'secret123', 'phone' => '79165554433']);
        $this->assertSame($buyer->id, DealContract::for($deal->fresh())->buyer_user_id);
    }
}
