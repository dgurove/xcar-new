<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Страховые и заказчики стоянки — один справочник вендоров: реквизиты,
 * договор, условия, контакты по ролям, адреса отправителей и прайс.
 * Обратной миграции нет: слитые заказчики и новые поля назад не разложить.
 */
return new class extends Migration
{
    /** Домены и адреса, которые до сих пор жили константой в разборе писем. */
    private const SENDERS = [
        'Т-Страхование' => [['tinsurance.ru', 'tinkoffinsurance.ru', 'tbank.ru'], 'tinkoff'],
        'Совкомбанк Страхование' => [['sovcomins.ru'], 'sovcombank'],
        'АльфаСтрахование' => [['alfastrah.ru'], 'generic'],
        'Энергогарант' => [['msk-garant.ru', 'energogarant.ru'], 'energogarant'],
        'ИНСАЙТ' => [['insightins.ru'], 'insight'],
        'Абсолют Страхование' => [['absolutins.ru'], 'generic'],
    ];

    public function up(): void
    {
        Schema::rename('insurers', 'vendors');
        Schema::table('offers', fn (Blueprint $t) => $t->renameColumn('insurer_id', 'vendor_id'));
        Schema::table('workflows', fn (Blueprint $t) => $t->renameColumn('insurer_id', 'vendor_id'));

        Schema::table('vendors', function (Blueprint $t) {
            $t->string('kind', 8)->default('insurer')->after('name');           // insurer | leasing | bank | other
            $t->string('legal_name', 200)->nullable();
            $t->string('inn', 12)->nullable();
            $t->string('kpp', 9)->nullable();
            $t->string('legal_address', 255)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->string('bank_account', 20)->nullable();
            $t->string('bank_corr', 20)->nullable();
            $t->string('bank_bic', 9)->nullable();
            $t->string('payment_purpose', 255)->nullable();                       // шаблон назначения платежа
            $t->string('agreement_number', 60)->nullable();
            $t->date('agreement_date')->nullable();
            $t->date('agreement_until')->nullable();
            $t->string('deal_format', 12)->default('supplier');                   // direct | commission | supplier
            $t->string('reward_kind', 10)->nullable();                            // difference | percent | fixed
            $t->unsignedInteger('reward_value')->nullable();
            $t->smallInteger('payment_days')->nullable();                         // рабочих дней на оплату
            $t->boolean('vat_included')->default(false);
            $t->smallInteger('answer_hours')->nullable();                         // срок ответа, если в письме нет
            $t->boolean('silence_means_buy')->default(false);
            $t->smallInteger('binding_days')->nullable();                         // срок обязывающего предложения
            $t->string('storage_payer', 8)->default('vendor');                    // vendor | owner | nobody
            $t->smallInteger('buyer_storage_after_days')->nullable();
            $t->boolean('release_without_payment')->default(false);
            $t->jsonb('senders')->default('[]');                                  // домены и адреса отправителей
            $t->string('parser', 16)->default('generic');
            $t->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $t->jsonb('intake_docs')->default('[]');                              // что прислать после приёма
            $t->text('intake_note')->nullable();
        });

        Schema::create('vendor_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('title', 120)->nullable();
            $t->string('role', 12)->default('other');                             // claims | sales | accounting | storage | other
            $t->string('email', 120)->nullable();
            $t->string('phone', 20)->nullable();
            $t->boolean('always_cc')->default(false);
            $t->boolean('is_default')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        foreach (DB::table('vendors')->get() as $v) {
            if ($v->contact_name || $v->phone || $v->email) {
                DB::table('vendor_contacts')->insert([
                    'vendor_id' => $v->id, 'name' => $v->contact_name ?: 'Контакт', 'role' => 'other',
                    'email' => $v->email, 'phone' => $v->phone, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['contact_name', 'phone', 'email']));

        foreach (self::SENDERS as $name => [$senders, $parser]) {
            $id = DB::table('vendors')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id')
                ?? DB::table('vendors')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('vendors')->where('id', $id)->update(['senders' => json_encode($senders), 'parser' => $parser]);
        }

        // Заказчики стоянки — те же компании, заведённые второй раз.
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->foreignId('vendor_id')->nullable()->after('color')->constrained('vendors')->nullOnDelete());
        foreach (DB::table('park_clients')->get() as $c) {
            $domains = array_values(array_filter((array) json_decode($c->sender_domains ?: '[]', true)));
            $vendor = DB::table('vendors')->whereRaw('lower(name) = ?', [mb_strtolower($c->name)])->first();
            if (! $vendor && $domains) {
                $vendor = DB::table('vendors')->get()->first(fn ($v) => array_intersect($domains, (array) json_decode($v->senders ?: '[]', true)));
            }
            $id = $vendor?->id ?? DB::table('vendors')->insertGetId(['name' => $c->name, 'senders' => json_encode($domains), 'notes' => $c->notes, 'created_at' => now(), 'updated_at' => now()]);
            if ($vendor) {
                $senders = array_values(array_unique(array_merge((array) json_decode($vendor->senders ?: '[]', true), $domains)));
                DB::table('vendors')->where('id', $id)->update([
                    'senders' => json_encode($senders),
                    'notes' => trim(($vendor->notes ? $vendor->notes."\n" : '').(string) $c->notes) ?: null,
                ]);
            }
            foreach ((array) json_decode($c->contacts ?: '[]', true) as $contact) {
                if (! array_filter($contact)) {
                    continue;
                }
                DB::table('vendor_contacts')->insert([
                    'vendor_id' => $id, 'name' => $contact['name'] ?? 'Контакт', 'role' => 'storage',
                    'email' => $contact['email'] ?? null, 'phone' => $contact['phone'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('park_vehicles')->where('client_id', $c->id)->update(['vendor_id' => $id]);
        }
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropConstrainedForeignId('client_id'));
        Schema::drop('park_clients');

        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('answer_by')->nullable();                               // ответ поставщику до
            $t->string('insured_name', 80)->nullable();
            $t->string('insured_phone', 20)->nullable();
            $t->jsonb('flags')->default('[]');                                    // credit | leasing | pledged | legal_owner | restricted | keys_pending
            $t->string('holder', 80)->nullable();                                 // залогодержатель / лизингодатель
            $t->jsonb('docs_required')->default('[]');
            $t->string('contact_name', 80)->nullable();                           // ответственный по убытку
            $t->string('contact_email', 120)->nullable();
        });

        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->string('category', 10)->nullable()->after('color');               // Cars\Category
            $t->boolean('oversize')->default(false);
            $t->string('contact_name', 80)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->jsonb('flags')->default('[]');
            $t->jsonb('docs_required')->default('[]');
            $t->jsonb('docs_done')->default('[]');
            $t->unsignedInteger('value')->nullable();                             // «ГОТС оценены N»
        });

        Schema::create('park_tariffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->cascadeOnDelete();
            $t->foreignId('yard_id')->nullable()->constrained('park_yards')->cascadeOnDelete();
            $t->string('category', 10)->nullable();
            $t->string('service', 12);
            $t->smallInteger('from_day')->default(1);
            $t->smallInteger('km_included')->nullable();
            $t->decimal('price', 10, 2);
            $t->boolean('vat')->default(false);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('note', 120)->nullable();
            $t->timestamps();
            $t->index(['service', 'valid_from']);
        });
        DB::statement('create unique index park_tariffs_cell on park_tariffs (vendor_id, yard_id, category, service, from_day, valid_from) nulls not distinct');
    }

    public function down(): void
    {
        throw new RuntimeException('Вендоры назад в страховых и заказчиков не раскладываются');
    }
};
