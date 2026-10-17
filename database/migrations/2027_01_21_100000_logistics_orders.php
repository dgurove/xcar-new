<?php

use App\Park\Pass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 18.10.2026, владелец: логист (Татьяна) ведёт в логистике и свои заявки — от своих заказчиков, не только от XCar; людей
 * заводят руками, а не только ссылкой; исполнителю — страница по ссылке без входа.
 * - `logistics_clients` — заказчики логиста (видит только она);
 * - `transports`: заказчик и деньги заявки (цена заказчику, цена исполнителю, когда оплачено), контакты на месте, код
 *   страницы исполнителя `trip_code`;
 * - `invites.manual` — ссылка, по которой человека завели руками (`AddPerson`): в списках ссылок её нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('logist_id')->index()->constrained('users')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('kind', 8)->default('company');
            $t->string('contact_name', 120)->nullable();
            $t->string('phone', 11)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('inn', 12)->nullable();
            $t->string('address', 500)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::table('transports', function (Blueprint $t) {
            $t->foreignId('client_id')->nullable()->index()->constrained('logistics_clients')->nullOnDelete();
            $t->decimal('client_price', 12, 2)->nullable();
            $t->timestamp('client_paid_at')->nullable();
            $t->decimal('executor_cost', 12, 2)->nullable();
            $t->timestamp('executor_paid_at')->nullable();
            $t->string('from_contact', 120)->nullable();
            $t->string('from_phone', 11)->nullable();
            $t->string('to_contact', 120)->nullable();
            $t->string('to_phone', 11)->nullable();
            $t->text('comment')->nullable();
            $t->string('trip_code', 24)->nullable()->unique();
        });

        Schema::table('invites', function (Blueprint $t) {
            $t->boolean('manual')->default(false);
        });

        // Задачам, у которых исполнитель уже есть, — ссылка для него сразу (дальше её ставит `Transport::saving`).
        DB::table('transports')->whereNotNull('executor_id')->whereNotIn('state', ['cancelled'])->orderBy('id')
            ->each(fn ($t) => DB::table('transports')->where('id', $t->id)->update(['trip_code' => Pass::freshCode()]));
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $t) => $t->dropColumn('manual'));
        Schema::table('transports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('client_id');
            $t->dropColumn(['client_price', 'client_paid_at', 'executor_cost', 'executor_paid_at', 'from_contact', 'from_phone', 'to_contact', 'to_phone', 'comment', 'trip_code']);
        });
        Schema::dropIfExists('logistics_clients');
    }
};
