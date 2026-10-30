<?php

namespace Tests\Feature;

use App\Mail\Extraction\Code;
use App\Offers\Console\MigtorgSync;
use App\Offers\Intake\MigtorgDrafts;
use App\Offers\Migtorg;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Черновики с Мигторга — только КАСКО (30.10.2026, владелец: «ПВУ это не наше», «с Минторга только с раздела каско»).
 * Ошибка молчит: ОСАГО заводилось сотнями, модераторы разбирали мусор руками.
 */
class MigtorgDraftsTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $alfa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['xcar.migtorg_email' => null, 'xcar.migtorg_password' => null]);
        $this->admin = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin], 'approved_at' => now()]);
        Vendor::query()->update(['drafts_from_migtorg' => false]);
        $this->alfa = Vendor::where('name', 'АльфаСтрахование')->firstOrFail();
        $this->alfa->update(['drafts_from_migtorg' => true]);
        // Вендор по виду номера — по прошлым предложениям того же вида.
        Offer::create(['vendor_id' => $this->alfa->id, 'claim_ref' => '0592/046/00001/26']);
    }

    private function lot(int $id, string $ref, string $section): void
    {
        DB::table('migtorg_lots')->insert(['id' => $id, 'claim_ref' => $ref, 'claim_ref_key' => Code::key($ref), 'title' => 'Mazda 6 2010',
            'seen_at' => now(), 'section' => $section]);
    }

    private function offerOf(string $ref): ?Offer
    {
        return Offer::whereCar(fn ($c) => $c->where('claim_ref_key', Code::key($ref)))->first();
    }

    public function test_only_casco_lot_opens_a_draft(): void
    {
        $this->lot(1, '0592/046/00002/26', 'casco');
        $this->lot(2, '0592/046/00003/26', 'osago');

        $this->assertSame(1, MigtorgDrafts::run());
        $this->assertSame('migtorg', $this->offerOf('0592/046/00002/26')?->created_via);
        $this->assertNull($this->offerOf('0592/046/00003/26'));
    }

    public function test_drop_osago_deletes_only_untouched_osago_drafts(): void
    {
        $this->lot(1, '0592/046/00002/26', 'casco');
        $this->lot(2, '0592/046/00003/26', 'casco');
        $this->lot(3, '0592/046/00004/26', 'casco');
        MigtorgDrafts::run();
        DB::table('migtorg_lots')->whereIn('id', [2, 3])->update(['section' => 'osago']);
        // Взяли в работу — закупочная есть: не «Без закупочной цены».
        $this->offerOf('0592/046/00004/26')->update(['floor_price' => 300000]);

        $this->artisan('migtorg:drop-osago')->assertSuccessful();
        $this->assertNotNull($this->offerOf('0592/046/00003/26'));

        $this->artisan('migtorg:drop-osago', ['--apply' => true])->assertSuccessful();
        $this->assertNotNull($this->offerOf('0592/046/00002/26'));
        $this->assertNull($this->offerOf('0592/046/00003/26'));
        $this->assertNotNull($this->offerOf('0592/046/00004/26'));
        // Лот помечен — второй раз черновик сам не заведётся.
        $this->assertSame(0, MigtorgDrafts::run());
    }

    public function test_drop_osago_keeps_drafts_people_touched(): void
    {
        $this->lot(1, '0592/046/00002/26', 'casco');
        $this->lot(2, '0592/046/00003/26', 'casco');
        $this->lot(3, '0592/046/00004/26', 'casco');
        MigtorgDrafts::run();
        DB::table('migtorg_lots')->update(['section' => 'osago']);
        $manager = User::create(['name' => 'Роман', 'phone' => '79000000017', 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('interests')->insert(['offer_id' => $this->offerOf('0592/046/00002/26')->id, 'user_id' => $manager->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('car_events')->insert(['car_id' => $this->offerOf('0592/046/00003/26')->car_id, 'user_id' => $this->admin->id, 'type' => 'note',
            'payload' => '{}', 'created_at' => now()]);

        $this->artisan('migtorg:drop-osago', ['--apply' => true])->assertSuccessful();
        $this->assertNotNull($this->offerOf('0592/046/00002/26'));
        $this->assertNotNull($this->offerOf('0592/046/00003/26'));
        $this->assertNull($this->offerOf('0592/046/00004/26'));
    }

    public function test_section_is_known_word_or_the_listed_one_and_never_erased(): void
    {
        $auction = fn (?string $type) => ['id' => 7, 'lot' => ['insurance_deal_number' => '0592/046/00009/26', 'insurance_type' => $type]];
        $this->assertSame('osago', Migtorg::row($auction('osago'), 'casco')['section']);
        $this->assertSame('casco', Migtorg::row($auction('property_casco'), 'casco')['section']);
        $this->assertNull(Migtorg::row($auction(null))['section']);

        $this->lot(7, '0592/046/00009/26', 'osago');
        DB::table('migtorg_lots')->upsert([MigtorgSync::toIndex(Migtorg::row($auction(null)), now())], ['id'], MigtorgSync::updates());
        $this->assertSame('osago', DB::table('migtorg_lots')->where('id', 7)->value('section'));
    }
}
