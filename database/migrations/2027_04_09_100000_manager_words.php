<?php

use App\Workflow\Presets\ManagerWords;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тексты маршрута менеджеру — без «вы» и повелительного (16.11.2026, `ManagerWords`): этапы, блоки и открытые дела, только
 * строки, равные пресетам. Поправленное в редакторе руками не трогаем; закрытые дела — история, как были.
 */
return new class extends Migration
{
    public function up(): void
    {
        $n = 0;
        foreach (ManagerWords::TITLES as $old => $new) {
            $n += DB::table('workflow_stages')->where('ask_title', $old)->update(['ask_title' => $new]);
            $n += DB::table('requirements')->whereNull('done_at')->where('title', $old)->update(['title' => $new]);
        }
        foreach (ManagerWords::TEXTS as $old => $new) {
            $n += DB::table('workflow_stages')->where('ask_text', $old)->update(['ask_text' => $new]);
            $n += DB::table('workflow_blocks')->where('text', $old)->update(['text' => $new]);
            $n += DB::table('requirements')->whereNull('done_at')->where('text', $old)->update(['text' => $new]);
        }
        echo "  тексты менеджеру: {$n} строк\n";
    }

    public function down(): void
    {
        foreach (ManagerWords::TITLES as $old => $new) {
            DB::table('workflow_stages')->where('ask_title', $new)->update(['ask_title' => $old]);
            DB::table('requirements')->whereNull('done_at')->where('title', $new)->update(['title' => $old]);
        }
        foreach (ManagerWords::TEXTS as $old => $new) {
            DB::table('workflow_stages')->where('ask_text', $new)->update(['ask_text' => $old]);
            DB::table('workflow_blocks')->where('text', $new)->update(['text' => $old]);
            DB::table('requirements')->whereNull('done_at')->where('text', $new)->update(['text' => $old]);
        }
    }
};
