<?php

namespace Tests\Feature;

use App\Offers\Actions\ApplyValuations;
use App\Offers\Actions\MatchValuations;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\Pricing;
use App\Offers\ValuationRun;
use App\Offers\ValuationText;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Оценить из текста → Закупочные» (17.11.2026, Альфа «Реализация»: «по цене без надбавок»): у вендора «Закупочная от
 * оценочной» закупочная = оценочная = сумма, наценка не встаёт — и когда закупочная уже такая; каждое сохранение — в истории окна.
 */
class ValuationModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_floor_mode_sets_floor_equal_to_amount_and_records_run(): void
    {
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'АльфаСтрахование тест', 'deal_format' => 'supplier', 'floor_by_value' => true]);
        $fresh = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'Z691/046/02300/26']);
        // Закупочная уже равна сумме, оценочная другая: сменится только оценочная — правило не должно пересчитать закупочную.
        $same = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '0531/046/00572/26']);
        app(UpdateOffer::class)($same, ['value' => 1000000, 'floor_price' => 1259000], $admin, ['why' => 'тест']);
        $this->assertGreaterThan(459000, Pricing::fromAmount($vendor->id, 459000)['floor_price'], 'с наценкой — больше суммы');

        $text = "Z691/046/02300/26 459000 грузовик\n0531/046/00572/26 1259000 Renault";
        $items = app(MatchValuations::class)($admin, ValuationText::parse($text)['rows'], false);
        $this->assertEqualsCanonicalizing([459000, 1259000], collect($items)->pluck('floor')->all(), 'в окне закупочная — сама сумма');
        $saved = ApplyValuations::prepare($admin, $items, false, 'text');
        // Оценочная вписана иначе — спор, «Перезаписать».
        app(ApplyValuations::class)($admin, $saved, [$fresh->id, $same->id], [$same->id => 'overwrite']);

        foreach ([[$fresh, 459000], [$same, 1259000]] as [$offer, $amount]) {
            $offer->refresh();
            $this->assertSame($amount, (int) $offer->floor_price, 'закупочная без наценки');
            $this->assertSame($amount, (int) $offer->value, 'оценочная равна закупочной');
        }
        $run = ValuationRun::sole();
        $this->assertFalse($run->markup);
        $this->assertSame(2, $run->cars);
        $this->assertSame([(string) $vendor->id => 2], $run->vendors);

        $this->actingAs($admin)->get('http://crm.xcar.localhost/offers/valuations/history')->assertOk()->assertSee('закупочные, без наценки')->assertSee('2 ТС');
    }
}
