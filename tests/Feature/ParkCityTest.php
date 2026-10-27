<?php

namespace Tests\Feature;

use App\Cars\Settlement;
use App\Mail\Message;
use App\Park\Actions\SetCity;
use App\Park\City;
use App\Park\CityGuess;
use App\Park\Request;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Город заявки парковки (`Park\CityGuess`, 27.10.2026): заявка ВСК из Питера встала в Москву, и питерский управляющий её
 * не видел — ошибка молчит, а машину никто не принимает. Не уверены — видна всем городам; решение человека не
 * перетирается; принятая — город парковки.
 */
class ParkCityTest extends TestCase
{
    use RefreshDatabase;

    private int $moscow;

    private int $spb;

    private Vendor $vendor;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        // Справочник мест приходит миграцией — города те же, что на проде.
        $this->moscow = City::main();
        $this->spb = Settlement::where('name', 'Санкт-Петербург')->orderBy('rank')->orderBy('id')->value('id')
            ?? Settlement::create(['name' => 'Санкт-Петербург', 'region_id' => 78, 'rank' => 1])->id;
        Yard::create(['name' => 'Измайловский лес', 'is_active' => true, 'settlement_id' => $this->moscow]);
        Yard::create(['name' => 'Краснопутиловская', 'is_active' => true, 'settlement_id' => $this->spb]);
        $this->vendor = Vendor::create(['name' => 'ВСК тест']);
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
    }

    /** Заявка из письма отправителя: ТС ждёт приёма, город решает дверь. */
    private function request(string $from, array $fields = []): Vehicle
    {
        $vehicle = Vehicle::create(['vendor_id' => $this->vendor->id, 'state' => VehicleState::Expected]);
        $letter = Message::create(['account_id' => $this->account, 'direction' => 'in', 'from_email' => $from, 'subject' => 'приём',
            'parsed' => ['fields' => collect($fields)->map(fn ($v) => ['value' => $v, 'source' => 'body'])->all()]]);
        Request::create(['vehicle_id' => $vehicle->id, 'type' => RequestType::Intake, 'letter_id' => $letter->id]);

        return $vehicle->refresh();
    }

    /** Отправитель, чьи прошлые машины приняли на парковку этого города. */
    private function history(string $from, int $city): void
    {
        $v = $this->request($from);
        $v->forceFill(['yard_id' => Yard::where('settlement_id', $city)->value('id')])->save();
    }

    public function test_address_in_letter_decides_city(): void
    {
        $v = $this->request('neshicheva.spb@vsk.ru', ['address' => 'г. Санкт-Петербург, Пулковское шоссе']);

        $this->assertSame([$this->spb, 'letter'], [(int) $v->city_id, $v->city_source]);
    }

    public function test_sender_with_one_city_goes_there_and_mixed_is_seen_by_all(): void
    {
        $this->history('akondratov@vsk.ru', $this->moscow);
        $this->assertSame($this->moscow, (int) $this->request('akondratov@vsk.ru')->city_id);

        $this->history('mixed@vsk.ru', $this->moscow);
        $this->history('mixed@vsk.ru', $this->spb);
        $unsure = $this->request('mixed@vsk.ru');
        $this->assertNull($unsure->city_id);

        // Питерский управляющий видит ТС без города, московскую — нет.
        $keeper = User::create(['name' => 'Никита', 'phone' => '79000000043', 'roles' => [Role::Parking], 'park_city_id' => $this->spb, 'approved_at' => now()]);
        Auth::login($keeper);
        $seen = Vehicle::pluck('id')->all();
        $this->assertContains($unsure->id, $seen);
        $this->assertNotContains(Vehicle::withoutGlobalScope(City::SCOPE)->where('city_id', $this->moscow)->whereNull('yard_id')->value('id'), $seen);
    }

    public function test_human_decision_survives_saves_until_intake(): void
    {
        $this->history('mixed@vsk.ru', $this->moscow);
        $this->history('mixed@vsk.ru', $this->spb);
        $v = $this->request('mixed@vsk.ru');
        $keeper = User::create(['name' => 'Никита', 'phone' => '79000000043', 'roles' => [Role::Parking], 'park_city_id' => $this->spb, 'approved_at' => now()]);

        app(SetCity::class)($v, $keeper, $this->spb);
        $v->refresh()->forceFill(['vendor_id' => Vendor::create(['name' => 'Другой'])->id])->save();
        $v->recity();
        $this->assertSame([$this->spb, CityGuess::MANUAL], [(int) $v->refresh()->city_id, $v->city_source]);

        // Управляющий решает только за свой город: московский не назначит Питер.
        $this->expectException(ValidationException::class);
        app(SetCity::class)($v, User::create(['name' => 'Москва', 'phone' => '79000000044', 'roles' => [Role::Parking], 'park_city_id' => $this->moscow, 'approved_at' => now()]), $this->spb);
    }

    public function test_yard_wins(): void
    {
        $v = $this->request('neshicheva.spb@vsk.ru', ['address' => 'г. Санкт-Петербург']);
        $v->forceFill(['yard_id' => Yard::where('settlement_id', $this->moscow)->value('id')])->save();

        $this->assertSame([$this->moscow, CityGuess::YARD], [(int) $v->refresh()->city_id, $v->city_source]);
    }
}
