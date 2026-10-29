<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Реквизиты вендора — только в его контрагенте (29.10.2026, владелец: «каждая вещь — одна сущность»). Раньше они жили
// и у вендора, и в `billing_parties` (копия при первом счёте) и дальше расходились. Вендору с реквизитами — контрагент;
// пустые поля контрагента — из вендора, при расхождении главнее контрагент, расхождения — в лог. Колонки вендора уходят.
return new class extends Migration
{
    /** Поле вендора => поле контрагента. */
    private const MAP = ['legal_name' => 'name', 'inn' => 'inn', 'kpp' => 'kpp', 'legal_address' => 'legal_address', 'bank_name' => 'bank_name',
        'bank_account' => 'account', 'bank_corr' => 'corr_account', 'bank_bic' => 'bik', 'payment_purpose' => 'payment_purpose'];

    public function up(): void
    {
        $filled = fn ($v) => $v !== null && trim((string) $v) !== '';
        foreach (DB::table('vendors')->orderBy('id')->get() as $vendor) {
            $values = array_filter(array_combine(self::MAP, array_map(fn ($f) => $vendor->{$f}, array_keys(self::MAP))), $filled);
            if (! $values) {
                continue;
            }
            $party = $vendor->party_id ? DB::table('billing_parties')->find($vendor->party_id) : null;
            if (! $party) {
                $id = DB::table('billing_parties')->insertGetId([
                    'kind' => $vendor->kind === 'person' ? 'person' : 'company', 'name' => $values['name'] ?? $vendor->name,
                    'is_self' => false, 'created_at' => now(), 'updated_at' => now(),
                ] + array_diff_key($values, ['name' => 1]));
                DB::table('vendors')->where('id', $vendor->id)->update(['party_id' => $id]);

                continue;
            }
            $fill = [];
            foreach ($values as $field => $value) {
                if (! $filled($party->{$field})) {
                    $fill[$field] = $value;
                } elseif (trim((string) $party->{$field}) !== trim((string) $value)) {
                    Log::warning("Реквизиты вендора {$vendor->id} ({$vendor->name}): {$field} у вендора «{$value}», у контрагента {$party->id} «{$party->{$field}}» — оставлен контрагент");
                }
            }
            if ($fill) {
                DB::table('billing_parties')->where('id', $party->id)->update($fill + ['updated_at' => now()]);
            }
        }

        Schema::table('vendors', fn ($t) => $t->dropColumn(array_keys(self::MAP)));
    }

    public function down(): void
    {
        Schema::table('vendors', function ($t) {
            $t->string('legal_name', 200)->nullable();
            $t->string('inn', 12)->nullable();
            $t->string('kpp', 9)->nullable();
            $t->string('legal_address', 255)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->string('bank_account', 20)->nullable();
            $t->string('bank_corr', 20)->nullable();
            $t->string('bank_bic', 9)->nullable();
            $t->string('payment_purpose', 255)->nullable();
        });
    }
};
