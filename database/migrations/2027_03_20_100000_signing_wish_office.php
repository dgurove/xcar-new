<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Workflow\Presets\Alfa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подписание у поставщика (11.11.2026, владелец по BMW X6 № 2610041185: «спрашивать менеджера о времени, когда Альфа пишет,
 * что договор начали готовить, чтобы, когда напишут „готов“, сразу им написать про время»):
 * - `deals.signing_asked_at` — дело менеджера «Когда покупатель сможет подписать?» (`Offers\Signing::askDays`);
 * - `deals.signing_wish` — его ответ `{days: [Y-m-d…], part: any|morning|afternoon, by, at}` (`SaveSigningWish`);
 * - `vendors.signing_office` — филиал: адрес, рабочие дни и часы, «по записи», контакты (`Signing::office`);
 * - письмо «Поставщику: время для подписания» — с днями покупателя `{{ signing_days }}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('signing_asked_at')->nullable();
            $t->jsonb('signing_wish')->nullable();
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->jsonb('signing_office')->nullable();
        });
        $letter = (new Alfa)->letters()['slots'];
        Template::where('scope', Scope::Offers)->where('name', $letter['name'])->update(['body' => $letter['body']]);
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['signing_asked_at', 'signing_wish']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('signing_office'));
    }
};
