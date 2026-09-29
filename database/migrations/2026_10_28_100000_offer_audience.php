<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кому показывать предложение: группы менеджеров, шаблоны показа волнами (кому сразу, кому через время,
 * кому никогда), правила у предложения снимком шаблона и посчитанный итог — кто и с какого момента видит.
 * Прежний круг (managers_limited + offer_managers) переносится правилом «эти — сразу» и удаляется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });
        Schema::create('manager_group_user', function (Blueprint $t) {
            $t->foreignId('group_id')->constrained('manager_groups')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['group_id', 'user_id']);
            $t->index('user_id');
        });
        Schema::create('audiences', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->unsignedInteger('position')->default(0);
            $t->jsonb('rules');
            $t->timestamps();
        });
        Schema::table('vendors', fn (Blueprint $t) => $t->foreignId('audience_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('offers', function (Blueprint $t) {
            $t->foreignId('audience_id')->nullable()->constrained()->nullOnDelete();
            $t->jsonb('audience_rules')->nullable();
        });
        Schema::create('offer_viewers', function (Blueprint $t) {
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('opens_at');
            $t->timestamp('notified_at')->nullable();
            $t->primary(['offer_id', 'user_id']);
            $t->index(['user_id', 'opens_at']);
        });

        $now = now();
        DB::table('audiences')->insert(['name' => 'Все сразу', 'position' => 0, 'rules' => json_encode([['delay' => 0, 'all' => true, 'groups' => [], 'users' => []]]), 'created_at' => $now, 'updated_at' => $now]);

        // Суженный круг — правило «эти менеджеры сразу»; общий остаётся пустым правилом (= все сразу).
        foreach (DB::table('offer_managers')->get()->groupBy('offer_id') as $offerId => $rows) {
            if (DB::table('offers')->where('id', $offerId)->value('managers_limited')) {
                DB::table('offers')->where('id', $offerId)->update(['audience_rules' => json_encode([['delay' => 0, 'all' => false, 'groups' => [], 'users' => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all()]])]);
            }
        }

        // Кто видит сейчас: опубликованные — те же, что видели; уведомление им уже уходило.
        $public = DB::table('offers')->whereIn('state', ['open', 'gallery'])->get(['id', 'published_at', 'managers_limited']);
        $managers = DB::table('users')->where('role', 'manager')->pluck('id');
        foreach ($public as $offer) {
            $ids = $offer->managers_limited ? DB::table('offer_managers')->where('offer_id', $offer->id)->pluck('user_id') : $managers;
            $at = $offer->published_at ?? $now;
            DB::table('offer_viewers')->insert($ids->map(fn ($id) => ['offer_id' => $offer->id, 'user_id' => $id, 'opens_at' => $at, 'notified_at' => $now])->all());
        }

        Schema::dropIfExists('offer_managers');
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('managers_limited'));
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->boolean('managers_limited')->default(false));
        Schema::create('offer_managers', function (Blueprint $t) {
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['offer_id', 'user_id']);
        });
        Schema::dropIfExists('offer_viewers');
        Schema::table('offers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('audience_id');
            $t->dropColumn('audience_rules');
        });
        Schema::table('vendors', fn (Blueprint $t) => $t->dropConstrainedForeignId('audience_id'));
        Schema::dropIfExists('audiences');
        Schema::dropIfExists('manager_group_user');
        Schema::dropIfExists('manager_groups');
    }
};
