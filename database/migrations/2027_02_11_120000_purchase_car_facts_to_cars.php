<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Факты машины — у машины (29.10.2026, владелец: «машины в закупке и в предложениях — одни и те же»). У лота закупки
 * лежали свои копии: категория (`kind` — тот же тип ТС, что `cars.category`, у 414 лотов, у машин — у 61), ключи словом,
 * руль, состояние, обременения и ФССП; при переносе в предложения они сворачивались в описание и метку. Теперь:
 * - тип ТС → `cars.category` (пустой; другой категории — по лоту, расхождения в лог);
 * - ключи «два» → `cars.keys_count` и `has_keys` (пустые);
 * - руль, состояние → новые поля машины `cars.steering`, `cars.condition`;
 * - ФССП и обременения → признак машины «Ограничения» (`cars.flags` `restricted`);
 * - `photos_count` — кэш, что расходился с файлами машины, — уходит, число считается по `media`.
 */
return new class extends Migration
{
    private const CATEGORY = ['passenger' => 'passenger', 'light' => 'light', 'truck' => 'truck', 'special' => 'special'];

    private const KEYS = ['нет' => 0, 'отсутствуют' => 0, '0' => 0, 'один' => 1, 'одна' => 1, '1' => 1, 'два' => 2, 'две' => 2, '2' => 2, 'три' => 3, '3' => 3, 'четыре' => 4, '4' => 4];

    public function up(): void
    {
        Schema::table('cars', function (Blueprint $t) {
            $t->string('steering', 20)->nullable();
            $t->string('condition', 120)->nullable();
        });

        foreach (DB::table('purchase_cars')->orderBy('id')->get(['car_id', 'kind', 'keys', 'steering', 'condition', 'encumbrance', 'fssp']) as $lot) {
            $car = DB::table('cars')->where('id', $lot->car_id)->first(['id', 'category', 'keys_count', 'has_keys', 'steering', 'condition', 'flags']);
            if (! $car) {
                continue;
            }
            $set = [];
            $category = self::CATEGORY[$lot->kind] ?? null;
            $group = fn (?string $c) => in_array($c, ['long', 'trailer'], true) ? 'truck' : $c;
            if ($category && ! $car->category) {
                $set['category'] = $category;
            } elseif ($category && $group($car->category) !== $category) {
                Log::info("cars#{$car->id}: тип ТС {$car->category}, в закупке {$lot->kind} — оставлен тип машины");
            }
            $keys = self::KEYS[mb_strtolower(trim((string) $lot->keys))] ?? null;
            if ($keys !== null && $car->keys_count === null) {
                $set['keys_count'] = $keys;
            }
            if ($keys !== null && $car->has_keys === null) {
                $set['has_keys'] = $keys > 0;
            }
            foreach (['steering', 'condition'] as $f) {
                if (filled($lot->{$f}) && blank($car->{$f})) {
                    $set[$f] = $lot->{$f};
                }
            }
            $restricted = $lot->fssp || str_contains(mb_strtolower((string) $lot->encumbrance), 'фссп');
            $flags = json_decode($car->flags ?? '[]', true) ?: [];
            if ($restricted && ! in_array('restricted', $flags, true)) {
                $set['flags'] = json_encode([...$flags, 'restricted']);
            }
            if ($set) {
                DB::table('cars')->where('id', $car->id)->update($set);
            }
        }

        // Метка «Ограничения ФССП» у предложений — теперь признак машины: метка снимается, признак остаётся.
        foreach (DB::table('offers')->whereRaw("jsonb_exists(tags, 'Ограничения ФССП')")->get(['id', 'car_id', 'tags']) as $o) {
            DB::table('offers')->where('id', $o->id)->update(['tags' => json_encode(array_values(array_diff(json_decode($o->tags, true), ['Ограничения ФССП'])), JSON_UNESCAPED_UNICODE)]);
            $flags = json_decode((string) DB::table('cars')->where('id', $o->car_id)->value('flags'), true) ?: [];
            if (! in_array('restricted', $flags, true)) {
                DB::table('cars')->where('id', $o->car_id)->update(['flags' => json_encode([...$flags, 'restricted'])]);
            }
        }

        Schema::table('purchase_cars', function (Blueprint $t) {
            $t->dropColumn(['kind', 'keys', 'steering', 'condition', 'encumbrance', 'fssp', 'photos_count']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_cars', function (Blueprint $t) {
            $t->string('kind', 12)->default('other');
            $t->string('keys', 32)->nullable();
            $t->string('steering', 16)->nullable();
            $t->string('condition', 120)->nullable();
            $t->string('encumbrance', 255)->nullable();
            $t->boolean('fssp')->nullable();
            $t->smallInteger('photos_count')->default(0);
        });
        DB::statement("update purchase_cars p set kind = case when c.category in ('long', 'trailer') then 'truck' else coalesce(c.category, 'other') end,
            steering = c.steering, condition = c.condition, fssp = jsonb_exists(c.flags, 'restricted') from cars c where c.id = p.car_id");
        Schema::table('cars', function (Blueprint $t) {
            $t->dropColumn(['steering', 'condition']);
        });
    }
};
