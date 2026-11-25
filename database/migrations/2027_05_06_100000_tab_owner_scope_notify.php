<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ответственный — за всю вкладку или только за горящее в ней, и шлём ли ему пуш и Telegram (26.11.2026, владелец про Катю: «во
 * вывозе она видит все этапы, но красным только просроченные и где срок обязывающего скоро сгорит, уведомления — только по
 * назначению исполнителя»). `scope`: `all` — вся вкладка, `urgent` — просроченное и горящий обязывающий (`Offers\Responsibility`).
 * `notify` — уведомления по строкам вкладки пушем и в Telegram (лента — всем, как была).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_tab_owners', function (Blueprint $t) {
            $t->string('scope', 10)->default('all');
            $t->boolean('notify')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('offer_tab_owners', function (Blueprint $t) {
            $t->dropColumn(['scope', 'notify']);
        });
    }
};
