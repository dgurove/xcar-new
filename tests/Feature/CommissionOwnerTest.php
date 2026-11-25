<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Mail\Extraction\CommitentFields;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DkpDocument;
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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Собственник по договору комиссии ПРАЙМ — физлицо, ИП или организация (25.11.2026, владелец: «может быть физлицо, юрлицо, ИП»;
 * «иногда да, Совком, иногда нет, иногда неправильно распознаётся»). Ошибка молчаливая и дорогая: в ДКП не тот собственник или
 * «в лице директора …» в названии.
 */
class CommissionOwnerTest extends TestCase
{
    use RefreshDatabase;

    /** Преамбула и колонка «КОМИТЕНТ» — как их отдаёт `AttachmentText` у договоров комиссии Совкомбанка. */
    private static function contract(string $who, string $block): string
    {
        return "ДОГОВОР КОМИССИИ № 865034/2026\nАО «Совкомбанк страхование», именуемое КОМИССИОНЕР, с одной стороны и {$who}, именуем"
            ."ый в дальнейшем КОМИТЕНТ с другой стороны, заключили настоящий Договор.\n| КОМИССИОНЕР ООО «ПРАЙМ» | КОМИТЕНТ {$block} КОМИТЕНТ ____/подпись/ |";
    }

    public function test_parses_entrepreneur_company_with_signer_and_person(): void
    {
        $ip = CommitentFields::extract(self::contract('ИП Петров Пётр Петрович',
            'ИП Петров Пётр Петрович Юридический адрес ИНН 344222299000 ОГРНИП 318344300012345 р\с 40802810911000000000'));
        $this->assertSame(PartyKind::Entrepreneur, $ip['kind']);
        $this->assertSame(['name' => 'Петров Пётр Петрович', 'inn' => '344222299000', 'ogrn' => '318344300012345'], $ip['party']);

        $org = CommitentFields::extract(self::contract('ООО «АВТОЛАЙФ ЛОГИСТИК» в лице директора Баширова Ришата Ильфатовича, действующего на основании Устава',
            '«АВТОЛАЙФ ЛОГИСТИК» Юридический адрес 117461, г. Москва, ул. Херсонская, д. 5 ИНН 7727467841 КПП 772701001'));
        $this->assertSame(PartyKind::Company, $org['kind']);
        $this->assertSame('ООО «АВТОЛАЙФ ЛОГИСТИК»', $org['party']['name']);
        $this->assertSame('директора Баширова Ришата Ильфатовича', $org['party']['signed_by']);
        $this->assertSame('Устава', $org['party']['director_basis']);
        $this->assertSame('117461, г. Москва, ул. Херсонская, д. 5', $org['party']['legal_address']);

        $person = CommitentFields::extract(self::contract('Иванов Иван Иванович',
            'Иванов Иван Иванович Дата рождения 01.02.1980 Паспорт 45 12 345678 Выдан 03.04.2010 ОВД Тверской Код подразделения 770-001 Адрес регистрации г. Москва'));
        $this->assertSame(PartyKind::Person, $person['kind']);
        $this->assertSame('45 12 345678', $person['party']['passport']);
    }

    private function deal(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Армен Хачян', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendorParty = Party::create(['kind' => 'company', 'name' => 'АО «Совкомбанк страхование»', 'inn' => '7812016906', 'kpp' => '771401001']);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission', 'party_id' => $vendorParty->id]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'supplier_vat' => 5, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, scheme: DealScheme::Prime);

        return [$deal->fresh(), $admin, $vendorParty];
    }

    public function test_owner_sheet_sets_vendor_entrepreneur_and_fixes_wrong_one(): void
    {
        [$deal, $admin, $vendorParty] = $this->deal();
        $url = 'http://'.config('xcar.crm_host').'/work/deals/'.$deal->id.'/owner';
        $this->actingAs($admin);

        // Сам поставщик — одним нажатием, его сторона.
        $this->put($url, ['mode' => 'vendor'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($vendorParty->id, DealContract::for($deal->fresh())->seller_party_id);
        $this->assertStringContainsString('АО «Совкомбанк страхование» (ИНН', strip_tags((string) (new DkpDocument(DealContract::for($deal->fresh())))->owner()));

        // ИП полями: банк и адрес не обязательны.
        $this->put($url, ['mode' => 'new', 'owner' => ['kind' => 'ip', 'name' => 'Петров Пётр Петрович', 'inn' => '344222299000', 'ogrn' => '318344300012345']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $contract = DealContract::for($deal->fresh());
        $ip = $contract->seller;
        $this->assertSame(PartyKind::Entrepreneur, $ip->kind);
        $this->assertNotSame($vendorParty->id, $ip->id);
        $this->assertTrue($contract->ownerReady());
        $this->assertSame('Индивидуальный предприниматель Петров Пётр Петрович (ОГРНИП 318344300012345, ИНН 344222299000)', strip_tags((string) (new DkpDocument($contract))->owner()));

        // Распознали не так — поправить: организация, та же сторона.
        $this->put($url, ['mode' => 'edit', 'owner' => ['kind' => 'company', 'org' => 'ООО «Ромашка»', 'inn' => '7727467841', 'ogrn' => '1027700000000']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $contract = DealContract::for($deal->fresh());
        $this->assertSame($ip->id, $contract->seller_party_id);
        $this->assertSame('ООО «Ромашка» (ОГРН 1027700000000, ИНН 7727467841)', strip_tags((string) (new DkpDocument($contract))->owner()));

        // Сторону поставщика правка не трогает — заводит свою.
        $this->put($url, ['mode' => 'vendor']);
        $this->put($url, ['mode' => 'edit', 'owner' => ['kind' => 'company', 'org' => 'АО «Другое»', 'inn' => '7700000000']])->assertSessionHasNoErrors();
        $this->assertSame('АО «Совкомбанк страхование»', $vendorParty->fresh()->name);
        $this->assertNotSame($vendorParty->id, DealContract::for($deal->fresh())->seller_party_id);

        // Страница машины: строка «Собственник».
        $this->get('http://'.config('xcar.crm_host').'/offers/'.$deal->offer->number)->assertOk()->assertSee('Собственник')->assertSee('АО «Другое»');
    }
}
