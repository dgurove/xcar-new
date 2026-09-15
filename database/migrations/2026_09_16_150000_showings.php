<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Показы: менеджер открыл предложение покупателю лично или группе покупателей.
 * Ровно одно из user_id / group_id. Покупатель видит предложение, пока показ
 * есть, предложение открыто и доступно его менеджеру.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('buyer_groups')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['manager_id', 'offer_id']);
            $table->index('user_id');
            $table->index('group_id');
        });
        DB::statement('ALTER TABLE showings ADD CONSTRAINT showings_one_target CHECK ((user_id IS NULL) <> (group_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX showings_user ON showings (offer_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX showings_group ON showings (offer_id, group_id) WHERE group_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('showings');
    }
};
