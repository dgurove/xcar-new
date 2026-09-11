<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Закупка — своя таблица, а не колонка в офферах: закупочной цены на
        // публичной модели нет, и доказывать нечего.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('title', 120)->nullable();
            $table->string('supplier', 80)->nullable();
            $table->string('state', 10)->default('draft')->index();   // draft | open | closed | archived
            $table->timestamp('offers_close_at')->nullable();
            $table->string('source_file', 255)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('dl', 40);                               // номер ДЛ поставщика — ключ строки
            $table->unsignedInteger('ref')->unique();               // наш номер наружу
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('car_models')->nullOnDelete();
            $table->string('brand_raw', 80)->nullable();
            $table->string('model_raw', 120)->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('vin', 17)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('transmission', 16)->nullable();
            $table->string('fuel', 16)->nullable();
            $table->unsignedSmallInteger('engine_volume')->nullable();
            $table->unsignedSmallInteger('engine_power')->nullable();
            $table->string('color', 32)->nullable();
            $table->string('steering', 16)->nullable();
            $table->string('keys', 32)->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('condition', 120)->nullable();
            $table->string('encumbrance', 255)->nullable();
            $table->boolean('fssp')->nullable();
            $table->string('vehicle_type', 120)->nullable();
            $table->string('kind', 12)->default('passenger')->index();
            $table->string('stage', 80)->nullable();
            $table->unsignedInteger('price_revalued')->nullable();
            $table->unsignedInteger('price_listing')->nullable();
            $table->string('site_url', 500)->nullable();
            $table->string('cloud_url', 500)->nullable();
            $table->jsonb('cloud_leftovers')->nullable();
            $table->string('specs_state', 8)->default('skipped');
            $table->string('specs_error', 300)->nullable();
            $table->timestamp('specs_at')->nullable();
            $table->string('photos_state', 8)->default('skipped');
            $table->string('photos_error', 300)->nullable();
            $table->timestamp('photos_at')->nullable();
            $table->unsignedSmallInteger('photos_count')->default(0);
            $table->jsonb('locked_fields')->default('[]');
            $table->boolean('is_published')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['purchase_id', 'dl']);
        });
        DB::statement('CREATE SEQUENCE IF NOT EXISTS purchase_car_refs START 1');

        Schema::create('purchase_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained('purchase_cars')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('comment', 500)->nullable();
            $table->string('state', 10)->default('active')->index();   // active | chosen | declined | withdrawn
            $table->timestamps();
            $table->index(['car_id', 'state']);
        });
        DB::statement("CREATE UNIQUE INDEX purchase_offers_one_active ON purchase_offers (car_id, user_id) WHERE state = 'active'");

        // Кому какие категории не показывать.
        Schema::create('purchase_restrictions', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('hidden_kinds')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_restrictions');
        Schema::dropIfExists('purchase_offers');
        Schema::dropIfExists('purchase_cars');
        DB::statement('DROP SEQUENCE IF EXISTS purchase_car_refs');
        Schema::dropIfExists('purchases');
    }
};
