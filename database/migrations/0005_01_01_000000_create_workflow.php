<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('contact_name', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Маршрут — на ветку: продажа у каждой страховой, вывоз только у тех,
        // чьи машины мы обязаны забрать. Ветка написана на маршруте.
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            $table->string('track', 8);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['insurer_id', 'track']);
        });

        // Блок — то, что видит менеджер: «Подписание», «Оплата». Этапы внутри
        // блока — наша кухня.
        Schema::create('workflow_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('text')->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_id')->constrained('workflow_blocks')->cascadeOnDelete();
            $table->string('name', 80);
            $table->smallInteger('position')->default(0);
            $table->string('waits_for', 10)->default('us');           // us | manager | supplier | nobody
            $table->unsignedInteger('limit_minutes')->nullable();
            $table->string('deadline_source', 16)->default('own');    // own | bids_close | insurer_deadline
            $table->string('offer_state', 16)->nullable();            // ветка продажи: что становится с оффером
            $table->string('car_place', 8)->nullable();               // ветка вывоза: где машина
            $table->string('ask_title', 120)->nullable();             // просьба к менеджеру, когда его ход
            $table->text('ask_text')->nullable();
            $table->string('asks', 10)->default('nothing');           // nothing | document | fields
            $table->jsonb('fields')->default('[]');                   // что менеджер вписывает: [{key,label,type}]
            $table->jsonb('staff_fields')->default('[]');             // что вписываем мы на входе, менеджеру видно
            $table->timestamps();
        });

        Schema::create('workflow_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->string('label', 60);
            $table->string('actor', 8)->default('staff');             // staff | manager | timer
            $table->string('confirm', 200)->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('insurer_id')->nullable()->after('state')->constrained()->nullOnDelete();
            $table->string('claim_ref', 60)->nullable()->after('insurer_id');
            $table->string('claim_ref_key', 60)->nullable()->after('claim_ref')->index();
            $table->date('insurer_deadline_at')->nullable()->after('claim_ref_key');
            $table->string('car_place', 8)->nullable()->after('insurer_deadline_at');
        });

        // Где оффер стоит на каждой ветке. Отдельной строкой на ветку, а не
        // зеркальными колонками: по deadline_at ходит крон, по stage_id — пресеты.
        Schema::create('offer_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('track', 8);
            $table->foreignId('stage_id')->constrained('workflow_stages')->restrictOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('block_entered_at');
            $table->timestamp('deadline_at')->nullable()->index();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
            $table->unique(['offer_id', 'track']);
        });

        // Просьба к менеджеру: появляется, когда оффер встаёт на этап с его
        // кнопками, закрывается его ответом или уходом с этапа.
        Schema::create('requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('text')->nullable();
            $table->string('asks', 10)->default('nothing');
            $table->jsonb('fields')->default('[]');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->jsonb('answer')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'done_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('notification_settings')->default('{}')->after('access');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_settings'));
        Schema::dropIfExists('requirements');
        Schema::dropIfExists('offer_positions');
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['insurer_id', 'claim_ref', 'claim_ref_key', 'insurer_deadline_at', 'car_place']));
        foreach (['workflow_exits', 'workflow_stages', 'workflow_blocks', 'workflows', 'insurers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
