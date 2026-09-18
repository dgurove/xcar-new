<?php

use App\Support\Surface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Три PWA — три хоста: адреса в уведомлениях и пушах без хоста, каждое приложение открывает у себя.
 * У подписки на пуш — хост, с которого её оформили: `navigate` строится на нём, а не на APP_URL.
 * Старые уведомления с абсолютными адресами CRM — на относительные.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('host', 255)->nullable();
        });

        $crm = rtrim(Surface::Crm->url(), '/');
        DB::statement("update notifications set data = jsonb_set(data, '{href}', to_jsonb(replace(data->>'href', ?, '/account/chats/'))) where data->>'href' like ?", ["$crm/work/chats/", "$crm/work/chats/%"]);
        DB::statement("update notifications set data = jsonb_set(data, '{href}', to_jsonb(replace(data->>'href', ?, '/offers/'))) where data->>'href' like ?", ["$crm/offers/", "$crm/offers/%"]);
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('host');
        });
    }
};
