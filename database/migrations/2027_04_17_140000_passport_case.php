<?php

use App\Billing\Passport\PassportCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Паспортные данные, распознанные раньше капсом, — новым видом (17.11.2026, владелец: «существующие паспортные данные переведи по
 * этой системе, только где капс; заново распознавание не прогонять — там много вручную поправленного»): место рождения и прописка —
 * `PassportCase` и только если значение целиком заглавными (набранное руками не трогается); «кем выдан» — заглавными везде, как в
 * бланке (тот же день, владелец). Без событий модели: меняется только регистр.
 */
return new class extends Migration
{
    public function up(): void
    {
        $caps = fn (?string $v) => $v !== null && trim($v) !== '' && $v === mb_strtoupper($v) && preg_match('/[А-ЯЁ]{2}/u', $v);
        DB::table('billing_parties')->where(fn ($q) => $q->whereNotNull('passport_issued')->orWhereNotNull('birth_place')->orWhereNotNull('reg_address'))
            ->orderBy('id')->each(function ($p) use ($caps) {
                $set = [];
                if ($p->passport_issued !== null && $p->passport_issued !== mb_strtoupper($p->passport_issued)) {
                    $set['passport_issued'] = mb_strtoupper($p->passport_issued);
                }
                foreach (['birth_place', 'reg_address'] as $key) {
                    if ($caps($p->{$key}) && ($new = PassportCase::format($p->{$key})) !== $p->{$key}) {
                        $set[$key] = $new;
                    }
                }
                $set && DB::table('billing_parties')->where('id', $p->id)->update($set);
            });
    }

    public function down(): void {}
};
