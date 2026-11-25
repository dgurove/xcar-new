<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Платёжное поручение» → «Документ об оплате» везде, где это читает человек (25.11.2026, владелец): тексты шагов маршрутов и
 * открытых просьб менеджеру. Подписи старых исходов («Платёжное поручение приложено») не трогаем: по ним читается история.
 */
return new class extends Migration
{
    private const WORDS = ['приложить платёжное поручение' => 'приложить документ об оплате', 'приложите платёжное поручение' => 'приложите документ об оплате'];

    public function up(): void
    {
        $this->swap(self::WORDS);
    }

    public function down(): void
    {
        $this->swap(array_flip(self::WORDS));
    }

    /** @param array<string, string> $words */
    private function swap(array $words): void
    {
        foreach ($words as $from => $to) {
            foreach (['ask_text', 'ask_title'] as $col) {
                DB::table('workflow_stages')->where($col, 'like', '%'.$from.'%')->update([$col => DB::raw('replace('.$col.", '".$from."', '".$to."')")]);
            }
            foreach (['text', 'title'] as $col) {
                DB::table('requirements')->whereNull('done_at')->where($col, 'like', '%'.$from.'%')->update([$col => DB::raw('replace('.$col.", '".$from."', '".$to."')")]);
            }
        }
    }
};
