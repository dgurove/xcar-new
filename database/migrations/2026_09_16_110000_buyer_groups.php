<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Группы покупателей менеджера: как он сам их назовёт; покупатель может быть в нескольких. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['manager_id', 'position']);
        });

        Schema::create('buyer_group_user', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('buyer_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_group_user');
        Schema::dropIfExists('buyer_groups');
    }
};
