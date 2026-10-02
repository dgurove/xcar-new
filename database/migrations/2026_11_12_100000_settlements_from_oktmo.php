<?php

use App\Cars\SettlementImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник населённых пунктов — из ОКТМО (02.10.2026): все города, посёлки, сёла и деревни с регионом и районом,
 * регионы с номером, как на номерах машин. Прежние города остаются теми же строками (`SettlementImport`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('oktmo', 5)->unique();
            $table->string('plate', 3);
            $table->string('name', 80);
            $table->string('short', 40);
            $table->string('match', 120);
        });
        Schema::table('settlements', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('region_code')->constrained()->nullOnDelete();
            $table->string('district', 160)->nullable()->after('region_id');
            $table->string('oktmo', 11)->nullable()->after('district');
            $table->unsignedSmallInteger('rank')->default(9)->after('oktmo');
        });
        // Поиск по началу имени (подсказка поля) и по имени целиком («ё» как «е», `Settlement::named`).
        DB::statement('create index settlements_name_prefix on settlements (lower(name) text_pattern_ops)');
        DB::statement("create index settlements_name_key on settlements ((replace(lower(name), 'ё', 'е')))");

        app(SettlementImport::class)();
    }

    public function down(): void
    {
        DB::statement('drop index if exists settlements_name_prefix');
        DB::statement('drop index if exists settlements_name_key');
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
            $table->dropColumn(['district', 'oktmo', 'rank']);
        });
        Schema::dropIfExists('regions');
    }
};
