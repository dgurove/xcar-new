<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_yards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('address', 255)->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('park_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->jsonb('contacts')->default('[]');          // [{name, phone, email}]
            $table->jsonb('sender_domains')->default('[]');    // письма с этих доменов — от него
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('park_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 60)->nullable();              // номер убытка страховой — так они зовут машину
            $table->string('ref_key', 60)->nullable()->index();
            $table->string('vin', 17)->nullable()->index();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('car_models')->nullOnDelete();
            $table->smallInteger('year')->nullable();
            $table->string('plate', 12)->nullable()->index();
            $table->string('color', 32)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('park_clients')->nullOnDelete();
            $table->string('state', 10)->default('expected')->index();   // expected | stored | released
            $table->foreignId('yard_id')->nullable()->constrained('park_yards')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->jsonb('damage_zones')->default('[]');
            $table->text('damage_note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('park_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $table->string('type', 12);                          // intake | inspection | tow | move | release
            $table->string('state', 10)->default('new')->index(); // new | done | cancelled
            $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $table->foreignId('yard_id')->nullable()->constrained('park_yards')->nullOnDelete();  // куда переставить
            $table->timestamp('planned_at')->nullable()->index();
            $table->timestamp('done_at')->nullable();
            $table->string('contact', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('park_vehicle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('mail_candidates', function (Blueprint $table) {
            $table->string('scope', 8)->default('offers')->after('id')->index();
            $table->foreignId('vehicle_id')->nullable()->after('offer_id')->constrained('park_vehicles')->nullOnDelete();
        });
        Schema::table('mail_threads', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('offer_id')->constrained('park_vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropConstrainedForeignId('vehicle_id'));
        Schema::table('mail_candidates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vehicle_id');
            $t->dropColumn('scope');
        });
        foreach (['park_vehicle_events', 'park_requests', 'park_vehicles', 'park_clients', 'park_yards'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
