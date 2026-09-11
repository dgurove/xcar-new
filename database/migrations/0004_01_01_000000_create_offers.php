<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Номер оффера — из последовательности, чтобы черновик и опубликованный
        // нумеровались одним рядом и номер не менялся при публикации.
        DB::statement('DROP SEQUENCE IF EXISTS offer_numbers');
        DB::statement('CREATE SEQUENCE offer_numbers START 1001');

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique()->default(DB::raw("nextval('offer_numbers')"));
            $table->string('state', 16)->default('draft')->index();

            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('car_models')->nullOnDelete();
            $table->smallInteger('year')->nullable()->index();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('vin', 17)->nullable()->index();
            $table->boolean('show_vin')->default(false);
            $table->string('body', 24)->nullable();
            $table->string('transmission', 16)->nullable();
            $table->string('drive', 8)->nullable();
            $table->string('fuel', 16)->nullable();
            $table->unsignedSmallInteger('engine_volume')->nullable();
            $table->unsignedSmallInteger('engine_power')->nullable();
            $table->string('color', 32)->nullable();

            $table->string('damage_cause', 24)->nullable();
            $table->jsonb('damage_zones')->nullable();
            $table->boolean('is_runnable')->nullable();
            $table->boolean('has_keys')->nullable();
            $table->string('papers', 16)->nullable();
            $table->date('incident_date')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('inspection_address')->nullable();

            $table->unsignedInteger('floor_price')->nullable();      // закупочная, наружу не показывается
            $table->unsignedInteger('repair_estimate')->nullable();
            $table->unsignedInteger('asking_price')->nullable();     // цена продажи
            $table->unsignedInteger('min_bid_price')->nullable();    // нижняя граница ставки
            $table->decimal('min_bid_share', 3, 2)->nullable();
            $table->boolean('prices_include_vat')->nullable();

            $table->jsonb('tags')->default('[]');
            $table->boolean('chat_enabled')->default(true);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('bids_close_at')->nullable()->index();
            $table->smallInteger('sort_weight')->default(0);
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('comment', 500)->nullable();
            $table->string('state', 16)->default('active')->index();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['offer_id', 'state']);
        });
        // У менеджера на оффере одна живая ставка.
        DB::statement("CREATE UNIQUE INDEX bids_one_active ON bids (offer_id, user_id) WHERE state = 'active'");

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('new')->index();
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['offer_id', 'user_id']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'offer_id']);
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bid_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount')->nullable();
            $table->string('state', 16)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX deals_one_active ON deals (offer_id) WHERE state = 'active'");

        Schema::create('offer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->index();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('color', 16)->default('grey');
            $table->smallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tags', 'offer_events', 'deals', 'favorites', 'interests', 'bids', 'offers'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP SEQUENCE IF EXISTS offer_numbers');
    }
};
