<?php

use App\Mail\Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Стоянка как процесс страховой: типы вендора из списка начальника, множитель покупателю и периодичность
 * счетов у вендора, «продано» и «кому выдать» на ТС, звонок и способ доставки в заявке,
 * «соответствует / не соответствует» в осмотре выдачи, шаблоны писем стоянки.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            Schema::table('vendors', function (Blueprint $t) {
                $t->decimal('buyer_rate_multiplier', 4, 2)->default(3);
                $t->string('billing_cadence', 8)->default('monthly'); // monthly | release
                $t->foreignId('report_template_id')->nullable()->constrained('mail_templates')->nullOnDelete();
                $t->foreignId('refusal_template_id')->nullable()->constrained('mail_templates')->nullOnDelete();
            });
            DB::table('vendors')->where('kind', 'other')->update(['kind' => 'fleet']);

            Schema::table('park_vehicles', function (Blueprint $t) {
                $t->date('sold_at')->nullable();
                $t->foreignId('sold_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
                $t->string('pickup_name', 120)->nullable();
                $t->string('pickup_phone', 20)->nullable();
                $t->string('pickup_note', 255)->nullable();
                $t->foreignId('buyer_party_id')->nullable()->constrained('billing_parties')->nullOnDelete();
                $t->string('billing_cadence', 8)->nullable();
            });

            Schema::table('park_requests', function (Blueprint $t) {
                $t->string('delivery', 4)->nullable(); // tow | self
                $t->timestamp('contacted_at')->nullable();
                $t->timestamp('next_call_at')->nullable();
            });
            DB::table('park_requests')->where('type', 'tow')->update(['delivery' => 'tow']);

            Schema::table('billing_invoices', fn (Blueprint $t) => $t->timestamp('sent_at')->nullable()); // когда ушёл письмом вендору

            Schema::table('park_inspections', function (Blueprint $t) {
                $t->boolean('matches')->nullable();
                $t->string('mismatch_note', 500)->nullable();
                $t->boolean('refused')->default(false);
            });

            foreach (array_keys(Template::PARK) as $key) {
                Template::park($key);
            }
        });
    }

    public function down(): void
    {
        Schema::table('park_inspections', fn (Blueprint $t) => $t->dropColumn(['matches', 'mismatch_note', 'refused']));
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn('sent_at'));
        Schema::table('park_requests', fn (Blueprint $t) => $t->dropColumn(['delivery', 'contacted_at', 'next_call_at']));
        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sold_message_id');
            $t->dropConstrainedForeignId('buyer_party_id');
            $t->dropColumn(['sold_at', 'pickup_name', 'pickup_phone', 'pickup_note', 'billing_cadence']);
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropConstrainedForeignId('report_template_id');
            $t->dropConstrainedForeignId('refusal_template_id');
            $t->dropColumn(['buyer_rate_multiplier', 'billing_cadence']);
        });
    }
};
