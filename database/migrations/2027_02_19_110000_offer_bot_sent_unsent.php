<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 03.11.2026: строки без сообщения, доставшиеся от старой ленты («пропустить все», показанное до 10.10.2026), держали бы
 * машину — вернётся в продажу, а карточку бот не пришлёт (`Telegram\Offers\Cards::claim`). Карточек за ними нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offer_bot_sent')->whereNull('message_id')->delete();
    }

    public function down(): void {}
};
