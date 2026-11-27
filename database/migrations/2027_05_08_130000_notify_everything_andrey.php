<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Уведомлять обо всём» (27.11.2026, Андрей: «пока сотрудники не освоились — много проебов, пусть мне приходит всё»;
 * `Offers\Responsibility::EVERYTHING`) — Андрею Кузнецову сразу, строкой «Ответственных».
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('users')->where('name', 'Андрей Кузнецов')->whereJsonContains('roles', 'admin')->value('id');
        if ($id && ! DB::table('offer_tab_owners')->where('preset', 'everything')->where('user_id', $id)->exists()) {
            DB::table('offer_tab_owners')->insert(['preset' => 'everything', 'user_id' => $id, 'scope' => 'all', 'notify' => true, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('offer_tab_owners')->where('preset', 'everything')->delete();
    }
};
