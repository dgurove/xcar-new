<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Notifications\BuyerContractDataNotice;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\SettleContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ДКП по шаблонам владельца (19.10.2026): три вида — собственник физлицу (Т-Страхование), ДКП и акт от ПРАЙМ, от Р.О.С.
 * Ошибка тут молчалива и дорога: договор подписывают на бумаге. Проверяем стороны, номер, НДС ставкой продавца,
 * исправленные места шаблонов (суд, без доверенности), акт; у Альфы без НДС договора нет — его даёт сама Альфа.
 */
class DkpDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(DealScheme $scheme, int $price = 1250000): Deal
    {
        Notification::fake();
        $this->admin ??= User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager ??= User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        static $n = 0;
        $offer = Offer::create(['vin' => 'XTA21099'.str_pad((string) ++$n, 9, '0', STR_PAD_LEFT), 'year' => 2024, 'color' => 'белый', 'floor_price' => 1000000, 'asking_price' => $price]);
        $offer->forceFill(['pts' => '164302075126838', 'sts' => '9912345678'])->save();
        $offer->car->forceFill(['keys_count' => 2])->save();

        return Deal::create(['offer_id' => $offer->id, 'buyer_id' => $this->manager->id, 'state' => DealState::Active, 'cost' => 1000000, 'amount' => $price, 'scheme' => $scheme]);
    }

    private function buyer(array $party): User
    {
        $user = User::create(['name' => $party['name'], 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now()]);
        $user->forceFill(['party_id' => Party::create($party)->id])->save();

        return $user;
    }

    private function html(Deal $deal): string
    {
        // Текст договора без разметки: короткие коды обёрнуты в неразрывные span, пропуски — линии.
        $html = $this->actingAs($this->admin)->get('http://crm.xcar.localhost/work/deals/'.$deal->id.'/dkp')->assertOk()->getContent();

        return preg_replace('/[ \t\n]+/', ' ', strip_tags(preg_replace('#<style.*?</style>#s', '', $html)));
    }

    public function test_prime_contract_with_act_number_and_vat(): void
    {
        $deal = $this->deal(DealScheme::Prime);
        $buyer = $this->buyer(['kind' => PartyKind::Company, 'name' => 'ООО «Автотрейд»', 'inn' => '7701234567', 'kpp' => '770101001', 'legal_address' => 'г. Москва, ул. Ленина, д. 1', 'director' => 'Петров П. П.', 'director_full' => 'Петров Пётр Петрович']);
        $contract = DealContract::for($deal);
        $contract->update(['buyer_user_id' => $buyer->id, 'price' => 1250000]);
        app(SettleContract::class)($contract->fresh());
        $year = now('Europe/Moscow')->year;
        $this->assertSame('ПР-1/'.$year, $contract->fresh()->numberLabel());

        $html = $this->html($deal);
        $this->assertStringContainsString('Договор № ПР-1/'.$year, $html);
        $this->assertStringContainsString('в лице Генерального директора Кузнецова Андрея Викторовича', $html);
        $this->assertStringContainsString('от имени которого на основании Устава действует Петров Пётр Петрович', $html);
        $this->assertStringContainsString('(один миллион двести пятьдесят тысяч) рублей 00 копеек, в том числе НДС 5 %', $html);
        $this->assertStringContainsString('ЭПТС', $html);
        $this->assertStringContainsString('приема-передачи поврежденного транспортного средства', $html);
        $this->assertStringContainsString('комплект ключей в количестве 2 шт.', $html);
        $this->assertStringNotContainsString('Арбитраж', $html);
        $this->assertStringNotContainsString('доверенност', $html);

        // Второй договор того же продавца — следующий номер; PDF собирается.
        $other = $this->deal(DealScheme::Prime);
        $c2 = DealContract::for($other);
        $c2->update(['buyer_user_id' => $buyer->id, 'price' => 900000]);
        app(SettleContract::class)($c2->fresh());
        $this->assertSame('ПР-2/'.$year, $c2->fresh()->numberLabel());
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/work/deals/'.$other->id.'/dkp.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_ros_contract_has_its_requisites_and_vat_22(): void
    {
        $deal = $this->deal(DealScheme::Ros, 1220000);
        $buyer = $this->buyer(['kind' => PartyKind::Company, 'name' => 'ООО «Автотрейд»', 'inn' => '7701234567', 'legal_address' => 'г. Москва', 'director' => 'Петров П. П.']);
        $contract = DealContract::for($deal);
        $contract->update(['buyer_user_id' => $buyer->id, 'price' => 1220000]);
        app(SettleContract::class)($contract->fresh());

        $html = $this->html($deal);
        $this->assertStringContainsString('Договор № РОС-1/', $html);
        $this->assertStringContainsString('ИНН/КПП 9704056013/770401001', $html);
        $this->assertStringContainsString('в лице Генерального директора Кузнецовой Екатерины Андреевны', $html);
        $this->assertStringContainsString("в том числе НДС 22 % — 220\u{00A0}000 (двести двадцать тысяч) рублей 00 копеек", $html);
    }

    public function test_owner_contract_between_two_people(): void
    {
        $deal = $this->deal(DealScheme::OwnerDkp, 750000);
        $buyer = $this->buyer(['kind' => PartyKind::Person, 'name' => 'Смирнова Анна Сергеевна', 'birth_at' => '1990-01-02', 'passport' => '4512345678',
            'passport_issued' => 'ГУ МВД России по г. Москве', 'passport_issued_at' => '2015-06-02', 'reg_address' => 'г. Москва, ул. Профсоюзная, д. 12']);
        $seller = Party::create(['kind' => PartyKind::Person, 'name' => 'Иванов Иван Иванович', 'birth_at' => '1980-05-06', 'passport' => '46 01 112233',
            'passport_issued' => 'ОВД Химки', 'passport_issued_at' => '2010-01-01', 'reg_address' => 'г. Химки']);
        DealContract::for($deal)->update(['buyer_user_id' => $buyer->id, 'seller_party_id' => $seller->id, 'price' => 750000]);

        $html = $this->html($deal);
        $this->assertStringContainsString('Мы, гр. Иванов Иван Иванович, 06.05.1980 г. р.', $html);
        $this->assertStringContainsString('паспорт серии 46 01 № 112233', $html);
        $this->assertStringContainsString('зарегистрирована по адресу', $html);
        $this->assertStringContainsString('именуемая в дальнейшем «Покупатель»', $html);
        $this->assertStringContainsString('электронный паспорт транспортного средства № 164302075126838', $html);
        $this->assertStringContainsString("750\u{00A0}000 (семьсот пятьдесят тысяч) рублей 00 копеек", $html);
        $this->assertStringNotContainsString('Договор №', $html);
        $this->assertStringNotContainsString('НДС', $html);
    }

    public function test_alfa_without_vat_has_no_contract(): void
    {
        $deal = $this->deal(DealScheme::Insurer);
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/work/deals/'.$deal->id.'/dkp')->assertNotFound();
    }

    public function test_manager_and_staff_set_contract_date_and_buyer_fills_own_data(): void
    {
        $deal = $this->deal(DealScheme::OwnerDkp, 750000);
        $buyer = $this->buyer(['kind' => PartyKind::Person, 'name' => 'Смирнова Анна Сергеевна']);
        app(SaveDealContract::class)(DealContract::for($deal), ['buyer_id' => (string) $buyer->id], $this->manager);
        Notification::assertSentTo($buyer, BuyerContractDataNotice::class);

        // Дату ставит менеджер у себя и сотрудник в CRM.
        $this->actingAs($this->manager)->put('http://xcar.localhost/deals/'.$deal->id.'/contract', ['signed_at' => '2026-10-21'])->assertRedirect();
        $this->assertSame('2026-10-21', DealContract::for($deal->fresh())->signed_at->toDateString());
        $this->actingAs($this->admin)->put('http://crm.xcar.localhost/work/deals/'.$deal->id.'/contract', ['signed_at' => '2026-10-22'])->assertRedirect();
        $this->assertSame('2026-10-22', $deal->fresh()->contract->signed_at->toDateString());
        $this->assertStringContainsString('22.10.2026', $this->html($deal));

        // Покупатель вносит паспорт сам; чужому экран закрыт.
        $this->actingAs($buyer)->get('http://xcar.localhost/account/contract')->assertOk()->assertSee('Данные для договора');
        $this->actingAs($buyer)->put('http://xcar.localhost/account/contract', ['buyer' => ['kind' => 'person', 'name' => 'Смирнова Анна Сергеевна', 'birth_at' => '1990-01-02',
            'passport' => '4512345678', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2015-06-02', 'reg_address' => 'г. Москва']])->assertRedirect();
        $this->assertSame('4512345678', $buyer->fresh()->party->passport);
        $other = $this->buyer(['kind' => PartyKind::Person, 'name' => 'Чужой Покупатель']);
        $this->actingAs($other)->get('http://xcar.localhost/account/contract')->assertNotFound();
    }
}
