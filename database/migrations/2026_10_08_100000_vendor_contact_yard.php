<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Парковка у контакта вендора: страховые ведут убытки по городам, и письма от питерского эксперта — о машинах,
 * которые стоят в Петербурге. По адресу отправителя ТС заводится сразу на нужную парковку (`StoreByLetters`),
 * а не «без парковки». Заодно заводится сам контакт Альфы по Петербургу — по её письмам мы уже работаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_contacts', function (Blueprint $table) {
            $table->foreignId('yard_id')->nullable()->after('phone')->constrained('park_yards')->nullOnDelete();
        });

        $vendor = DB::table('vendors')->where('name', 'АльфаСтрахование')->value('id');
        $yard = DB::table('park_yards')->where('name', 'Краснопутиловская')->value('id');
        $email = 'kolbasinamp@alfastrah.ru';
        if ($vendor && $yard && ! DB::table('vendor_contacts')->where('email', $email)->exists()) {
            DB::table('vendor_contacts')->insert([
                'vendor_id' => $vendor, 'name' => 'Колбасина Мария Петровна',
                'role' => 'claims', 'email' => $email, 'yard_id' => $yard,
                'always_cc' => false, 'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vendor_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('yard_id');
        });
    }
};
