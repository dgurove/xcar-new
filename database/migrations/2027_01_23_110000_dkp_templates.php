<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ДКП по шаблонам владельца (19.10.2026): у договора ПРАЙМ и Р.О.С. — номер у продавца за год (`issuer` — чей номер); документы ТС — у машины
 * (госномер, СТС, ПТС там уже были, кузов и шасси — новые), с договора их колонки снимаются (на проде пусты все 41);
 * у нашей стороны — полное наименование, подписант полностью и «в лице …» готовым родительным падежом. Реквизиты
 * Р.О.С. — из шаблона договора, только в пустые поля.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $t) {
            $t->string('body_no', 40)->nullable();
            $t->string('chassis_no', 40)->nullable();
        });
        // Перенос того немногого, что могли вписать в договор, в пустые поля машины.
        foreach (DB::table('deal_contracts as c')->join('deals as d', 'd.id', '=', 'c.deal_id')->join('offers as o', 'o.id', '=', 'd.offer_id')
            ->whereNotNull('o.car_id')->get(['o.car_id', 'c.plate', 'c.sts', 'c.pts', 'c.body_no', 'c.chassis_no']) as $r) {
            $car = DB::table('cars')->where('id', $r->car_id)->first(['plate', 'sts', 'pts']);
            $set = array_filter(['plate' => $car->plate ? null : $r->plate, 'sts' => $car->sts ? null : $r->sts, 'pts' => $car->pts ? null : $r->pts,
                'body_no' => $r->body_no, 'chassis_no' => $r->chassis_no]);
            $set && DB::table('cars')->where('id', $r->car_id)->update($set);
        }
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->dropColumn(['plate', 'sts', 'pts', 'pts_issued', 'body_no', 'chassis_no', 'engine_no']);
            $t->string('issuer', 8)->nullable();
            $t->unsignedInteger('number')->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->unique(['issuer', 'year', 'number']);
        });
        Schema::table('billing_parties', function (Blueprint $t) {
            $t->string('full_name')->nullable();
            $t->string('director_full', 120)->nullable();
            $t->string('signed_by')->nullable();
        });
        foreach (['prime' => 'company', 'ros' => 'ros_company'] as $seller => $key) {
            $c = config('xcar.'.$key);
            $row = DB::table('billing_parties')->where('seller', $seller)->first();
            if (! $row) {
                continue;
            }
            $map = ['full_name' => 'full_name', 'director_full' => 'director_full', 'signed_by' => 'signed_by', 'inn' => 'inn', 'kpp' => 'kpp', 'ogrn' => 'ogrn',
                'legal_address' => 'address', 'bank_name' => 'bank', 'bik' => 'bik', 'account' => 'account', 'corr_account' => 'corr_account', 'director' => 'director'];
            $set = [];
            foreach ($map as $col => $from) {
                if (blank($row->{$col}) && filled($c[$from] ?? null)) {
                    $set[$col] = $c[$from];
                }
            }
            $set && DB::table('billing_parties')->where('id', $row->id)->update($set);
        }
    }

    public function down(): void
    {
        Schema::table('billing_parties', fn (Blueprint $t) => $t->dropColumn(['full_name', 'director_full', 'signed_by']));
        Schema::table('deal_contracts', function (Blueprint $t) {
            $t->dropUnique(['issuer', 'year', 'number']);
            $t->dropColumn(['issuer', 'number', 'year']);
            foreach (['plate', 'sts', 'pts', 'body_no', 'chassis_no', 'engine_no'] as $c) {
                $t->string($c, 40)->nullable();
            }
            $t->string('pts_issued')->nullable();
        });
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn(['body_no', 'chassis_no']));
    }
};
