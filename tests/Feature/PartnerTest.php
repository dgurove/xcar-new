<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Jobs\ReadCarDocs;
use App\Mail\Extraction\Code;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Поставщик на partner.xcar.ru (07.11.2026, владелец: «не палить, что у нас есть парсер документов и Мигторг»; «тщательно
 * проверить на отсутствие дыр»). Доступ и утечки — ошибка молчалива и дорога: поставщик увидел бы чужие машины, наши
 * вкладки, слово «Мигторг», источник поля или адрес медиатеки с именем `migtorg-N`.
 */
class PartnerTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKS = '/migtorg|мигторг|распозна|парсер|парсинг|из документ|из письм|\/media\/|\bлот\b/iu';

    private User $alfaMan;

    private Vendor $alfa;

    private Vendor $t;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alfa = Vendor::where('name', 'АльфаСтрахование')->firstOrFail();
        $this->t = Vendor::where('name', 'Т-Страхование')->firstOrFail();
        $this->brand = Brand::resolve('Kia');
        CarModel::resolve($this->brand, 'Rio');
        $this->alfaMan = User::create(['name' => 'Ирина Соколова', 'login' => 'alfa.partner', 'roles' => [Role::Supplier], 'vendor_id' => $this->alfa->id, 'approved_at' => now()]);
    }

    private function draft(Vendor $vendor, string $ref, array $extra = []): Offer
    {
        return Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => $ref, 'brand_id' => $this->brand->id, 'model_id' => CarModel::where('brand_id', $this->brand->id)->value('id')] + $extra);
    }

    private function url(string $path): string
    {
        return Surface::Partner->url($path);
    }

    public function test_other_hosts_send_supplier_to_partner(): void
    {
        foreach ([Surface::Site->url('/offers'), Surface::Crm->url('/offers'), Surface::Logistics->url('/transports')] as $url) {
            $this->actingAs($this->alfaMan)->get($url)->assertRedirect(Surface::Partner->url());
        }
        $this->assertSame(Surface::Partner, Surface::onlyFor($this->alfaMan));
        $this->assertFalse($this->alfaMan->isStaff());
    }

    public function test_partner_host_has_nothing_else(): void
    {
        foreach (['/account/chats/support', '/account/notifications', '/deals', '/gallery', '/search?q=a', '/reference/vendors', '/files/1', '/work/mail', '/offers/twins', '/space/conveyor'] as $path) {
            $this->actingAs($this->alfaMan)->get($this->url($path))->assertNotFound();
        }
        $this->actingAs($this->alfaMan)->get($this->url('/account'))->assertOk();
        // Сотруднику партнёр закрыт — стена.
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000301', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->actingAs($admin)->get($this->url('/offers'))->assertForbidden();
        // Витрины и галереи поставщику нет и через двери моделей.
        $this->draft($this->alfa, '0001/046/00001/26', ['state' => OfferState::Draft, 'gallery_until' => now()->addDay()]);
        $this->assertSame(0, Offer::visibleTo($this->alfaMan)->count());
    }

    public function test_only_own_drafts_without_floor(): void
    {
        $mine = $this->draft($this->alfa, '0001/046/00002/26');
        $priced = $this->draft($this->alfa, '0001/046/00003/26', ['floor_price' => 300_000]);
        $foreign = $this->draft($this->t, 'AUT-26-111111');

        $this->actingAs($this->alfaMan)->get($this->url('/offers'))->assertOk()
            ->assertSee('0001/046/00002/26')->assertDontSee('0001/046/00003/26')->assertDontSee('AUT-26-111111');
        $this->actingAs($this->alfaMan)->get($this->url('/offers/'.$mine->id))->assertOk();
        $this->actingAs($this->alfaMan)->get($this->url('/offers/'.$priced->id))->assertNotFound();
        $this->actingAs($this->alfaMan)->get($this->url('/offers/'.$foreign->id))->assertNotFound();
    }

    public function test_update_takes_only_allowed_fields_and_counts_floor(): void
    {
        $offer = $this->draft($this->alfa, '0001/046/00004/26');
        $rules = json_encode([[500_000, 50_000, 0], [2_000_000, 0, 12], [null, 200_000, 0]]);

        $this->actingAs($this->alfaMan)->put($this->url('/offers/'.$offer->id), [
            'value' => '1 500 000', 'floor_rules' => $rules, 'floor_price' => '1', 'asking_price' => '9 000 000', 'vendor_id' => $this->t->id,
            'claim_ref' => 'AUT-26-999999', 'show_vin' => '0', 'recommended' => '1', 'mileage' => '45 000',
        ])->assertRedirect();

        $offer->refresh();
        $this->assertSame(1_500_000, (int) $offer->value);
        $this->assertSame(1_680_000, $offer->floor_price);
        $this->assertNull($offer->asking_price);
        $this->assertSame($this->alfa->id, $offer->vendor_id);
        $this->assertSame('0001/046/00004/26', $offer->claim_ref);
        $this->assertSame(45_000, (int) $offer->mileage);
        $this->assertSame([[500_000, 50_000, 0], [2_000_000, 0, 12], [null, 200_000, 0]], Pricing::rulesFor($offer));
        // Цену отдал — предложение ушло к нам, у поставщика его больше нет.
        $this->actingAs($this->alfaMan)->get($this->url('/offers/'.$offer->id))->assertNotFound();
    }

    /**
     * Цена из строки списка (08.11.2026, `price_row`): ответ JSON, закупочная по ступеням предложения; без марки и модели —
     * 422 «Укажите марку и модель». В редакторе без цены «Сохранить» оставляет на месте.
     */
    public function test_price_from_list_row(): void
    {
        $offer = $this->draft($this->alfa, '0001/046/00005/26');
        $this->actingAs($this->alfaMan)->put($this->url('/offers/'.$offer->id), ['mileage' => '5 000'])->assertRedirectContains('/offers/'.$offer->id);
        $this->actingAs($this->alfaMan)->putJson($this->url('/offers/'.$offer->id), ['value' => '700 000', '_fields' => ['value']])
            ->assertOk()->assertExactJson(['sent' => true, 'floor' => 770_000]);
        $offer->refresh();
        $this->assertSame(770_000, $offer->floor_price);
        $this->assertSame(5_000, (int) $offer->mileage);

        $bare = Offer::create(['vendor_id' => $this->alfa->id, 'claim_ref' => '0001/046/00006/26']);
        $this->actingAs($this->alfaMan)->putJson($this->url('/offers/'.$bare->id), ['value' => '700 000', '_fields' => ['value']])
            ->assertUnprocessable()->assertJsonValidationErrors('brand_id');
        $this->assertNull($bare->refresh()->floor_price);
    }

    public function test_find_claim_outcomes(): void
    {
        $mine = $this->draft($this->alfa, '0001/046/00005/26');
        $this->draft($this->alfa, '0001/046/00006/26', ['floor_price' => 300_000]);
        $this->draft($this->t, 'AUT-26-222222');
        $archived = $this->draft($this->alfa, '0001/046/00007/26', ['floor_price' => 400_000, 'value' => 350_000]);
        $archived->forceFill(['state' => OfferState::Archived])->save();
        LegacyClaim::create(['claim_ref' => '0001/046/00008/26', 'position' => 1]);
        $find = fn (string $ref) => $this->actingAs($this->alfaMan)->post($this->url('/offers/find'), ['claim_ref' => $ref]);

        $find('0001/046/00005/26')->assertRedirect($this->url('/offers/'.$mine->id));
        foreach (['0001/046/00006/26', 'AUT-26-222222', '0001/046/00008/26'] as $busy) {
            $find($busy)->assertSessionHasErrors(['claim_ref' => 'ТС с этим номером уже в работе']);
        }

        $find('0001/046/00007/26')->assertRedirect($this->url('/offers/'.$archived->id));
        $archived->refresh();
        $this->assertSame(OfferState::Draft, $archived->state);
        $this->assertNull($archived->floor_price);
        // Оценочная — у машины, это и заявленная стоимость парковки: возврат её не стирает.
        $this->assertSame(350_000, (int) $archived->value);

        // Архивное, что поставщику всё равно не открыть (было на аукционе, в сделке, в гараже), — «в работе», и не трогается.
        $auction = $this->draft($this->alfa, '0001/046/00012/26', ['floor_price' => 500_000]);
        $auction->forceFill(['state' => OfferState::Archived, 'auction_at' => now()])->save();
        $find('0001/046/00012/26')->assertSessionHasErrors(['claim_ref' => 'ТС с этим номером уже в работе']);
        $this->assertSame([OfferState::Archived, 500_000], [$auction->refresh()->state, $auction->floor_price]);

        // ТС чужого вендора на парковке без продажи — «в работе»: своё предложение на чужую машину не встаёт.
        Vehicle::create(['ref' => '0001/046/00013/26', 'vendor_id' => $this->t->id, 'state' => VehicleState::Stored, 'accepted_at' => now()]);
        $find('0001/046/00013/26')->assertSessionHasErrors(['claim_ref' => 'ТС с этим номером уже в работе']);
        $this->assertFalse(Offer::whereCar(fn ($c) => $c->where('claim_ref_key', Code::key('0001/046/00013/26')))->exists());

        $find('0001/046/00009/26')->assertRedirect();
        $new = Offer::whereCar(fn ($c) => $c->where('claim_ref_key', Code::key('0001/046/00009/26')))->firstOrFail();
        $this->assertSame([$this->alfa->id, $this->alfaMan->id, 'partner'], [$new->vendor_id, $new->moderator_id, $new->created_via]);
        $this->assertTrue($new->heldByPartner());
    }

    public function test_empty_value_keeps_car_value_and_price(): void
    {
        // Оценочная машины (заявленная стоимость парковки) есть, закупочной нет: сохранение без оценочной не ставит цену и не
        // стирает её.
        $offer = $this->draft($this->alfa, '0001/046/00014/26', ['value' => 640_000]);
        $this->actingAs($this->alfaMan)->put($this->url('/offers/'.$offer->id), ['value' => '', 'vin' => '', 'mileage' => '10 000', 'floor_rules' => json_encode(Pricing::RULES)])->assertRedirect();
        $offer->refresh();
        $this->assertSame(640_000, (int) $offer->value);
        $this->assertNull($offer->floor_price);
        $this->actingAs($this->alfaMan)->get($this->url('/offers/'.$offer->id))->assertOk()->assertDontSee('640');
    }

    public function test_foreign_vin_is_busy_without_details(): void
    {
        $other = $this->draft($this->t, 'AUT-26-444444', ['vin' => 'XTA21140064123456']);
        $offer = $this->draft($this->alfa, '0001/046/00015/26');
        $this->actingAs($this->alfaMan)->from($this->url('/offers/'.$offer->id))->put($this->url('/offers/'.$offer->id), ['vin' => 'XTA21140064123456'])
            ->assertSessionHasErrors(['vin' => 'ТС с этим VIN уже в работе']);
        $this->assertNull($offer->refresh()->vin);
        $this->assertNotSame($offer->car_id, $other->refresh()->car_id);
        // Чужое предложение — 404 ещё до проверки полей: по ответу не узнать, есть ли оно.
        $this->actingAs($this->alfaMan)->put($this->url('/offers/'.$other->id), ['year' => 'не год'])->assertNotFound();
    }

    public function test_moderators_see_partner_drafts(): void
    {
        $this->actingAs($this->alfaMan)->post($this->url('/offers/find'), ['claim_ref' => '0001/046/00016/26']);
        $draft = Offer::whereCar(fn ($c) => $c->where('claim_ref_key', Code::key('0001/046/00016/26')))->firstOrFail();
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000302', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $this->assertTrue($draft->isEditableBy($moderator));
    }

    public function test_pages_say_nothing_internal(): void
    {
        $offer = $this->draft($this->alfa, '0001/046/00010/26', ['description' => 'Передний удар']);
        $offer->log(OfferEventType::Updated, null, ['source' => 'migtorg', 'lot' => 815720]);
        $offer->addMedia(UploadedFile::fake()->image('migtorg-1.jpg', 800, 600))->usingFileName('migtorg-1.jpg')->usingName('migtorg-1')->withCustomProperties(['migtorg' => 'fa154c9d'])->toMediaCollection('photos');
        $offer->addMedia(UploadedFile::fake()->create('migtorg-25.pdf', 10, 'application/pdf'))->usingFileName('migtorg-25.pdf')->toMediaCollection('papers');

        foreach (['/offers', '/offers/'.$offer->id] as $path) {
            $html = $this->actingAs($this->alfaMan)->get($this->url($path))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression(self::LEAKS, strip_tags($html, '<a><img>').' '.$html, $path);
        }
        // Кадр и документ — своими адресами, с нейтральным именем.
        $photo = $offer->photos()->first();
        $this->actingAs($this->alfaMan)->get($this->url('/photos/'.$photo->id))->assertOk()->assertHeader('Content-Disposition', 'inline; filename="foto-'.$photo->id.'.jpg"');
        $paper = $offer->papers()->first();
        $this->assertStringNotContainsStringIgnoringCase('migtorg', (string) $this->actingAs($this->alfaMan)->get($this->url('/files/'.$paper->id))->assertOk()->headers->get('Content-Disposition'));
    }

    /** HTML или SVG от внешнего человека не выполняется на нашем хосте: такого не принять, а принятое — только скачиванием. */
    public function test_files_are_not_pages(): void
    {
        $offer = $this->draft($this->alfa, '0001/046/00017/26');
        $this->actingAs($this->alfaMan)->post($this->url('/offers/'.$offer->id.'/media'), ['file' => UploadedFile::fake()->createWithContent('x.html', '<script>alert(1)</script>'), 'collection' => 'papers'])->assertStatus(422);
        $doc = $offer->addMedia(UploadedFile::fake()->createWithContent('a.docx', 'PK'))->toMediaCollection('papers');
        $res = $this->actingAs($this->alfaMan)->get($this->url('/files/'.$doc->id))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_foreign_media_is_closed(): void
    {
        $foreign = $this->draft($this->t, 'AUT-26-333333');
        $foreign->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaCollection('photos');
        $this->actingAs($this->alfaMan)->get($this->url('/photos/'.$foreign->photos()->first()->id))->assertNotFound();
    }

    public function test_supplier_upload_triggers_nothing(): void
    {
        $offer = $this->draft($this->alfa, '0001/046/00011/26');
        Queue::fake();
        $this->actingAs($this->alfaMan)->post($this->url('/offers/'.$offer->id.'/media'), ['file' => UploadedFile::fake()->create('акт.pdf', 10, 'application/pdf'), 'collection' => 'papers'])->assertOk();
        $this->actingAs($this->alfaMan)->post($this->url('/offers/'.$offer->id.'/media'), ['file' => UploadedFile::fake()->image('кадр.jpg', 800, 600)])->assertOk();
        Queue::assertNotPushed(ReadCarDocs::class);
        $photo = $offer->refresh()->photos()->first();
        $this->assertSame($this->alfaMan->id, $photo->getCustomProperty('partner'));
        $this->assertNull($photo->getCustomProperty('unmarked'));
        // Своё удаляет, чужое — нет.
        $ours = $offer->addMedia(UploadedFile::fake()->image('b.jpg', 400, 300))->toMediaCollection('photos');
        $this->actingAs($this->alfaMan)->delete($this->url('/offers/'.$offer->id.'/media/'.$ours->id))->assertForbidden();
        $this->actingAs($this->alfaMan)->delete($this->url('/offers/'.$offer->id.'/media/'.$photo->id))->assertOk();
    }

    /** «Ввести данные» поставщику (08.11.2026): логином, без телефона; им он входит на partner. */
    public function test_supplier_added_by_login(): void
    {
        $admin = User::create(['name' => 'Админ', 'phone' => '79990000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $add = fn (array $extra) => $this->actingAs($admin)->post(Surface::Crm->url('/settings/users/invites'), ['mode' => 'person', 'role' => 'supplier',
            'vendor_id' => $this->alfa->id, 'first_name' => 'Олег', 'person_phone' => '+7 999 111-22-33'] + $extra);

        $add(['person_login' => ''])->assertSessionHasNoErrors()->assertSessionHas('credentials', fn ($c) => $c['via'] === 'Логин' && $c['phone'] === 'alfa' && str_contains($c['url'], 'partner.'));
        $user = User::where('login', 'alfa')->firstOrFail();
        $this->assertNull($user->phone);
        $this->assertTrue($user->isSupplier());
        $this->assertSame($this->alfa->id, $user->vendor_id);
        $add(['person_login' => ''])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('login', 'alfa2')->exists());
        $add(['person_login' => 'Alfa.Partner'])->assertSessionHasErrors('person_login');

        $add(['person_login' => 'oleg.alfa'])->assertSessionHasNoErrors();
        $password = session('credentials')['password'];
        auth()->logout();
        $this->post($this->url('/login'), ['login' => 'oleg.alfa', 'password' => $password])->assertRedirect($this->url('/offers'));
    }
}
