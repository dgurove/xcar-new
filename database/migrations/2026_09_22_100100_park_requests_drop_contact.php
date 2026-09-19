<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Контакт заявки одной строкой дублировал `contact_name` + `contact_phone`: телефон переезжает в телефон, остальное — в имя. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('park_requests')->whereNotNull('contact')->where('contact', '!=', '')->get(['id', 'contact', 'contact_name', 'contact_phone']) as $r) {
            $phone = preg_match('/(\+?\d[\d\s\-()]{9,}\d)/', $r->contact, $m) ? preg_replace('/[^\d+]/', '', $m[1]) : null;
            $name = trim(preg_replace('/\s{2,}/', ' ', str_replace($m[1] ?? '', '', $r->contact)), ' ,;-') ?: null;
            DB::table('park_requests')->where('id', $r->id)->update([
                'contact_phone' => $r->contact_phone ?: $phone, 'contact_name' => $r->contact_name ?: $name,
            ]);
        }
        Schema::table('park_requests', fn (Blueprint $t) => $t->dropColumn('contact'));
    }

    public function down(): void
    {
        Schema::table('park_requests', fn (Blueprint $t) => $t->string('contact')->nullable());
    }
};
