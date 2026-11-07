<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Rejections;
use App\Cars\Sources\Pick;
use App\Cars\Sources\Values;
use App\Cars\Sources\Variants;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Данные машины из разных источников (07.11.2026, владелец: «одно подставляет из документов, другое из Мигторга, спорят»;
 * «жмёшь „Взять“ — „обновите“, обновил — всё висит»). Ошибка молчит: неверный год или VIN уходит в договор.
 */
class CarSourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id]);
        app(UpdateOffer::class)($this->offer, ['year' => 2018, 'color' => 'Белый', 'plate' => 'А123ВС77'], $this->admin);
        $this->offer = $this->offer->fresh();
    }

    /** Значение источника строкой `car_values`, как кладёт `Collect`. */
    private function value(string $field, mixed $value, string $source, string $id, string $title): void
    {
        $row = Values::of([$field => ['value' => $value]], $this->offer->carRecord())[$field] ?? null;
        $this->assertNotNull($row, "{$field} {$value} записывается");
        DB::table('car_values')->insert(['car_id' => $this->offer->car_id, 'field' => $field, 'source' => $source, 'source_id' => $id, 'title' => $title,
            'write' => json_encode($row['write']), 'key' => $row['key'], 'label' => $row['label'], 'created_at' => now()]);
    }

    public function test_variants_grouped_and_pick_writes_exactly_the_variant(): void
    {
        $this->value('year', 2019, 'migtorg', '805732', 'Мигторг, лот 805732');
        $this->value('year', 2019, 'doc', '812', 'ПТС');
        $this->value('year', 2020, 'doc', 'm45', 'Договор комиссии');
        $this->value('year', 2018, 'letter', '7', 'Письмо 06.11');

        $year = Variants::for($this->offer->carRecord(), $this->offer)['year'];
        $this->assertSame('2018', $year['current']['label']);
        $this->assertSame(['Письмо 06.11'], array_column($year['current']['sources'], 'title'), 'согласный источник — у значения сейчас');
        $this->assertSame(['2019', '2020'], array_column($year['options'], 'label'));
        $this->assertCount(2, $year['options'][0]['sources'], 'одинаковые склеены');
        $this->assertSame(1, Variants::recount($this->offer->carRecord()->fresh()));

        // Видел «2018», а уже поменяли — не пишем, ответ — что стоит теперь.
        [$state, $toast] = app(Pick::class)->choose($this->offer, 'year', '2020', '2017', $this->admin);
        $this->assertSame(Pick::STALE, $state);
        $this->assertStringContainsString('теперь 2018', $toast);
        $this->assertSame(2018, $this->offer->fresh()->year);

        [$state] = app(Pick::class)->choose($this->offer, 'year', '2020', '2018', $this->admin);
        $this->assertSame(Pick::DONE, $state);
        $this->assertSame(2020, $this->offer->fresh()->year);
        $this->assertArrayNotHasKey('year', Variants::for($this->offer->fresh()->carRecord(), $this->offer->fresh()), 'выбор гасит спор: проигравшие отклонены');
        $this->assertSame(0, $this->offer->carRecord()->fresh()->value_conflicts);
        $this->assertTrue($this->offer->carRecord()->fresh()->byHuman('year'));

        // Отклонено общее для машины: парковка ТС той же машины спора не видит.
        $vehicle = Vehicle::create(['car_id' => $this->offer->car_id, 'vendor_id' => $this->offer->vendor_id, 'state' => VehicleState::Expected]);
        $this->assertArrayNotHasKey('year', Variants::for($vehicle->carRecord(), $vehicle));

        // Новый третий вариант — снова спор.
        $this->value('year', 2021, 'doc', '900', 'СТС');
        $this->assertSame(['2021'], array_column(Variants::for($this->offer->fresh()->carRecord(), $this->offer->fresh())['year']['options'], 'label'));
    }

    public function test_keep_and_same_plate_in_latin(): void
    {
        $this->value('plate', 'A123BC77', 'letter', '7', 'Письмо');
        $this->assertArrayNotHasKey('plate', Variants::for($this->offer->carRecord(), $this->offer), 'A123BC77 латиницей и А123ВС77 — один номер');
        $this->value('color', 'Черный', 'migtorg', '1', 'Мигторг');
        [$state] = app(Pick::class)->keep($this->offer, 'color', mb_strtolower('Белый'), $this->admin);
        $this->assertSame(Pick::DONE, $state);
        $this->assertSame('Белый', $this->offer->fresh()->color);
        $this->assertArrayNotHasKey('color', Variants::for($this->offer->fresh()->carRecord(), $this->offer->fresh()));
    }

    public function test_fill_empty_when_sources_agree_not_when_cleared_or_disputed(): void
    {
        $this->value('mileage', 64179, 'migtorg', '1', 'Мигторг');
        $this->value('mileage', 64179, 'doc', '2', 'Акт');
        $this->value('engine_power', 120, 'doc', '2', 'Акт');
        $this->value('engine_power', 150, 'doc', '3', 'ПТС');
        app(Pick::class)->fillEmpty($this->offer->carRecord());
        $this->assertSame(64179, $this->offer->fresh()->mileage, 'источники согласны — вписано само');
        $this->assertNull($this->offer->fresh()->engine_power, 'спорят — ждёт выбора');
        $this->assertSame(['120 л. с.', '150 л. с.'], array_column(Variants::for($this->offer->fresh()->carRecord(), $this->offer->fresh())['engine_power']['options'], 'label'));

        // Человек стёр пробег — источник его не возвращает.
        app(UpdateOffer::class)($this->offer->fresh(), ['mileage' => null], $this->admin);
        app(Pick::class)->fillEmpty($this->offer->fresh()->carRecord());
        $this->assertNull($this->offer->fresh()->mileage);
    }

    public function test_new_model_is_a_variant_and_joins_the_catalog_when_picked(): void
    {
        $bmw = Brand::create(['slug' => 'bmw', 'name' => 'BMW']);
        $x6 = CarModel::create(['brand_id' => $bmw->id, 'slug' => 'x6', 'name' => 'X6']);
        app(UpdateOffer::class)($this->offer, ['brand_id' => $bmw->id, 'model_id' => $x6->id], $this->admin);
        $this->offer = $this->offer->fresh();
        DB::table('car_values')->insert(['car_id' => $this->offer->car_id, 'field' => 'car', 'source' => 'doc', 'source_id' => '5', 'title' => 'ПТС',
            ...(fn ($r) => ['write' => json_encode($r['write']), 'key' => $r['key'], 'label' => $r['label']])(Values::of(['brand' => ['value' => 'BMW'], 'model' => ['value' => 'iX3']])['car']),
            'created_at' => now()]);
        $this->assertFalse(CarModel::where('name', 'iX3')->exists(), 'чтение справочник не пополняет');
        $car = Variants::for($this->offer->carRecord(), $this->offer)['car'];
        $this->assertSame('BMW iX3', $car['options'][0]['label']);

        app(Pick::class)->choose($this->offer, 'car', $car['options'][0]['key'], $car['current']['key'], $this->admin);
        $this->assertSame('iX3', $this->offer->fresh()->model?->name, 'выбрали — модель в справочнике и в машине');
        $this->assertArrayNotHasKey('car', Variants::for($this->offer->fresh()->carRecord(), $this->offer->fresh()));
    }

    /** Шум распознавания — не спор (08.11.2026, прод: треть из 114 машин со спорами). */
    public function test_recognition_noise_is_the_same_value(): void
    {
        $key = fn (string $field, mixed $value) => Values::of([$field => ['value' => $value]], null, trusted: true)[$field]['key'];
        $this->assertTrue(Values::same('vin', $key('vin', 'LS4ASE2A3PJ104389'), $key('vin', 'LB4ASE2A3PJ104389')), 'VIN: знак OCR');
        $this->assertFalse(Values::same('vin', $key('vin', 'LS4ASE2A3PJ104389'), $key('vin', 'XTA21900000000001')));
        $this->assertTrue(Values::same('pts', $key('pts', '78 УХ 719622'), $key('pts', '78 YX 719622')), 'ПТС латиницей');
        $this->assertTrue(Values::same('pts', $key('pts', '16 OP 553416'), $key('pts', '16 OR 553416')), 'ПТС: знак OCR');
        $this->assertFalse(Values::same('sts', $key('sts', '77 43 673235'), $key('sts', '77 36 594660')), 'другой СТС');
        $this->assertTrue(Values::same('color', $key('color', 'Серебристый'), Values::row('color', ['color' => 'Серебряный'])['key']));
        $this->assertTrue(Values::same('color', $key('color', 'Серый'), Values::row('color', ['color' => 'Черный серый'])['key']));
        $this->assertFalse(Values::same('color', $key('color', 'Белый'), $key('color', 'Черный')));
        $this->assertTrue(Values::same('mileage', $key('mileage', 3713), $key('mileage', 3700)));
        $this->assertFalse(Values::same('mileage', $key('mileage', 44263), $key('mileage', 44)));
        $this->assertTrue(Values::same('engine_volume', $key('engine_volume', 7698), $key('engine_volume', 7750)));

        $model = function (string $brand, string $slug, string $name) {
            $b = Brand::firstOrCreate(['slug' => $slug], ['name' => $brand]);

            return 'b'.$b->id.':m'.CarModel::create(['brand_id' => $b->id, 'slug' => \Str::slug($name).'-'.random_int(1, 99999), 'name' => $name])->id;
        };
        $this->assertTrue(Values::same('car', $model('УАЗ', 'uaz', 'UAZ PROFI'), $model('УАЗ', 'uaz', 'Profi')));
        $this->assertTrue(Values::same('car', $model('Lada', 'lada', '2190 Granta'), $model('Lada', 'lada', 'Granta')));
        $this->assertTrue(Values::same('car', $model('Tank', 'tank', '300'), $model('Tank', 'tank', 'GWM 300')));
        $this->assertTrue(Values::same('car', $model('JAC', 'jac', 'Аф77 N2 Lj'), $model('JAC', 'jac', 'АФ77N2LJ-02')));
        $this->assertFalse(Values::same('car', $model('Chery', 'chery', 'Tiggo 7'), $model('Chery', 'chery', 'Tiggo 7L')));
        $this->assertFalse(Values::same('car', $model('Omoda', 'omoda', 'C5'), $model('Omoda', 'omoda', 'S5')));

        // Отклонили вариант — тот же с шумом не встаёт.
        $this->value('pts', '16 OP 553416', 'doc', '1', 'ПТС');
        $this->value('pts', '16 OR 553416', 'doc', '2', 'Скан ПТС');
        $this->value('pts', '16 ОР 999999', 'doc', '3', 'Другой ПТС');
        $pts = Variants::for($this->offer->carRecord(), $this->offer)['pts'];
        $this->assertCount(2, $pts['options'], 'знак OCR склеен в один вариант');
        $this->assertCount(2, $pts['options'][0]['sources']);
    }

    public function test_unwritable_is_no_variant(): void
    {
        $other = Offer::create(['vendor_id' => $this->offer->vendor_id]);
        app(UpdateOffer::class)($other, ['vin' => 'XTA21900000000001'], $this->admin);
        $this->assertArrayNotHasKey('vin', Values::of(['vin' => ['value' => 'XTA21900000000001']], $this->offer->carRecord()), 'VIN другой машины');
        $this->assertArrayNotHasKey('year', Values::of(['year' => ['value' => 1890]]));
        $this->assertArrayNotHasKey('color', Values::of(['color' => ['value' => 'AUTOMOBILE']]), 'обрывок OCR — не цвет');
        $this->assertArrayNotHasKey('car', Values::of(['brand' => ['value' => 'Неизвестная марка'], 'model' => ['value' => 'X']]));
    }

    public function test_endpoint_answers_json(): void
    {
        $this->value('year', 2019, 'migtorg', '1', 'Мигторг');
        $this->actingAs($this->admin)->postJson('http://'.config('xcar.crm_host').'/offers/'.$this->offer->number.'/fields/year', ['key' => '2019', 'seen' => '2018'])
            ->assertOk()->assertJson(['ok' => true, 'stale' => false]);
        $this->assertSame(2019, $this->offer->fresh()->year);
        $this->assertTrue(DB::table('field_rejections')->where('subject', Rejections::car($this->offer->car_id))->where('value', '2018')->exists());
    }
}
