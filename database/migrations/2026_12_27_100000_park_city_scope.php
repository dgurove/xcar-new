<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Питер — свой управляющий (владелец 09.10.2026): он видит только свою парковку и письма питерской Альфы, московские —
 * только Москву, весь парк — администраторы парковки. Видимость — по городу (`App\Park\City`):
 * - у парковки город (`park_yards.settlement_id`): три московские — Москва, Краснопутиловская — Петербург;
 * - у ТС город храним (`park_vehicles.city_id`): по её парковке, без парковки — по парковке вендора по умолчанию,
 *   иначе Москва; переведённая в другой город без известной парковки держит город перевода;
 * - у вендора парковка по умолчанию (`home_yard_id`) — по ней новые ТС встают на место и письма вендора считаются
 *   письмами этого города; и от кого выставляем счета (`park_seller`: null — ИП Кузнецов, `prime` — ООО «ПРАЙМ»);
 * - у человека и ссылки — город (`park_city_id`), пусто — весь парк;
 * - счёт парковки — признаком (`billing_invoices.park`): раньше им был продавец ИП, а Питер выставляет от ПРАЙМ.
 *
 * Альфа СПб: парковка Краснопутиловская, счета от ПРАЙМ (так Питер выставлял всегда), ставки — фикса по типу ТС как у
 * ВСК, пока не придёт договор (владелец: «там не лесенка, там фикса по типу авто, возьми пока как у ВСК»).
 */
return new class extends Migration
{
    private const MOSCOW_YARDS = ['1 мая', 'Измайловский лес', 'Шоссе Энтузиастов'];

    private const SPB_YARD = 'Краснопутиловская';

    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->foreignId('home_yard_id')->nullable()->constrained('park_yards')->nullOnDelete();
            $t->string('park_seller', 8)->nullable();
        });
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('park_city_id')->nullable()->constrained('settlements')->nullOnDelete());
        Schema::table('invites', fn (Blueprint $t) => $t->foreignId('park_city_id')->nullable()->constrained('settlements')->nullOnDelete());
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->foreignId('city_id')->nullable()->index()->constrained('settlements')->nullOnDelete());
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->boolean('park')->default(false)->index());

        $moscow = $this->city('Москва', 77);
        $spb = $this->city('Санкт-Петербург', 78);
        DB::table('park_yards')->whereIn('name', self::MOSCOW_YARDS)->whereNull('settlement_id')->update(['settlement_id' => $moscow]);
        DB::table('park_yards')->where('name', self::SPB_YARD)->update(['settlement_id' => $spb]);
        $spbYard = DB::table('park_yards')->where('name', self::SPB_YARD)->value('id');

        $alfa = DB::table('vendors')->where('name', 'АльфаСтрахование СПб')->value('id');
        if ($alfa) {
            DB::table('vendors')->where('id', $alfa)->update(['home_yard_id' => $spbYard, 'park_seller' => 'prime', 'rate_by_value' => false]);
            $this->vskRates($alfa);
        }

        DB::table('billing_invoices')->where('seller', 'park')->update(['park' => true]);

        // Город ТС: парковка → парковка вендора по умолчанию → Москва.
        DB::statement('update park_vehicles v set city_id = coalesce(
            (select y.settlement_id from park_yards y where y.id = v.yard_id),
            (select y.settlement_id from vendors d join park_yards y on y.id = d.home_yard_id where d.id = v.vendor_id),
            ?)', [$moscow]);

        // Управляющие, что уже есть, — московские; администраторы парковки видят всё.
        DB::table('users')->whereRaw("roles @> '[\"parking\"]'::jsonb")->whereRaw("not roles @> '[\"park_admin\"]'::jsonb")->update(['park_city_id' => $moscow]);
    }

    private function city(string $name, int $region): ?int
    {
        return DB::table('settlements')->where('name', $name)->where('region_id', $region)->orderBy('id')->value('id');
    }

    /** Ставки Альфы СПб — строки ВСК с начала 2025 года (раньше по таблице не заводим); лестница по стоимости снята. */
    private function vskRates(int $alfa): void
    {
        $vsk = DB::table('vendors')->where('name', 'ВСК')->value('id');
        $rows = $vsk ? DB::table('park_tariffs')->where('vendor_id', $vsk)->whereNull('yard_id')->whereNull('valid_to')->get() : collect();
        if ($rows->isEmpty()) {
            return;
        }
        DB::table('park_tariffs')->where('vendor_id', $alfa)->delete();
        foreach ($rows as $r) {
            $row = (array) $r;
            unset($row['id']);
            DB::table('park_tariffs')->insert(['vendor_id' => $alfa, 'valid_from' => '2025-01-01', 'from_value' => null, 'created_at' => now(), 'updated_at' => now()] + $row);
        }
    }

    public function down(): void
    {
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn('park'));
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropConstrainedForeignId('city_id'));
        Schema::table('invites', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_city_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('park_city_id'));
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropConstrainedForeignId('home_yard_id');
            $t->dropColumn('park_seller');
        });
    }
};
