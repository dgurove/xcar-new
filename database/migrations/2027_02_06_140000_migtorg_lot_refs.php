<?php

use App\Mail\Extraction\Code;
use App\Offers\Console\MigtorgSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Номер дела лота без хвоста (26.10.2026): «6592/PVU/03331/26 САМАРА+» → «6592/PVU/03331/26» (`MigtorgSync::ref`), ключ —
 * от него: иначе лот не находил предложение с тем же номером, а черновик с Мигторга по нему не заводился.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('migtorg_lots')->where('claim_ref', '~', '\s')->select('id', 'claim_ref')->orderBy('id')->each(function ($lot) {
            $ref = MigtorgSync::ref($lot->claim_ref);
            if ($ref !== $lot->claim_ref) {
                DB::table('migtorg_lots')->where('id', $lot->id)->update(['claim_ref' => $ref, 'claim_ref_key' => mb_substr((string) Code::key($ref), 0, 80)]);
            }
        });
    }

    public function down(): void {}
};
