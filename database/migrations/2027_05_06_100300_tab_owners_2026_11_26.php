<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ответственные с 26.11.2026 (владелец со слов сотрудников, «Настройки → Ответственные» потом правит админ):
 * - Катя (30): закупочные цены, аукцион, оплата поставщику — без уведомлений; «Назначить исполнителя» целиком; остальной вывоз —
 *   только горящее; горящий обязывающий во всех вкладках;
 * - Алина (21): «Спросить менеджера», «Проверить реквизиты», счета, «Ждём оплату ТС», проверка оплат; «Ждём договор» — горящее;
 *   «Покупатель оплачивает» — тихо; общие вкладки сделок;
 * - Лиза (22): документы на вывоз, контакты и письма о вывозе, договор, «Оригиналы»; общие вкладки сделок;
 * - Андрей (4): «Ждём оплату подбора» вместо «Менеджер должен нам», остальное его — как было.
 */
return new class extends Migration
{
    private const PLAN = [
        30 => ['nofloor' => ['all', false], 'auction' => ['all', false], 'payout' => ['all', false], 'pickup_assign' => ['all', true],
            'pickup_contacts' => ['urgent', true], 'pickup_docs' => ['urgent', true], 'pickup_letters' => ['urgent', true], 'pickup_moving' => ['urgent', true],
            'binding' => ['all', false]],
        21 => ['nofloor' => ['all', true], 'ask' => ['all', true], 'verify' => ['all', true], 'letter' => ['all', true], 'answered' => ['all', true],
            'extra' => ['all', true], 'bill' => ['all', true], 'contract' => ['all', true], 'paying' => ['all', true], 'buyer' => ['all', false],
            'check' => ['all', true], 'arrived' => ['all', true], 'signed' => ['urgent', true], 'garage' => ['all', true], 'todo' => ['all', true],
            'finished' => ['all', true], 'all_deals' => ['all', true]],
        22 => ['nofloor' => ['all', true], 'letter' => ['all', true], 'answered' => ['all', true], 'extra' => ['all', true], 'contract' => ['all', true],
            'originals' => ['all', true], 'pickup_contacts' => ['all', true], 'pickup_docs' => ['all', true], 'pickup_letters' => ['all', true],
            'garage' => ['all', true], 'todo' => ['all', true], 'finished' => ['all', true], 'all_deals' => ['all', true]],
    ];

    public function up(): void
    {
        $exists = fn (int $id) => DB::table('users')->where('id', $id)->exists();
        DB::transaction(function () use ($exists) {
            foreach (self::PLAN as $user => $tabs) {
                if (! $exists($user)) {
                    continue;
                }
                DB::table('offer_tab_owners')->where('user_id', $user)->whereNotIn('preset', array_keys($tabs))->delete();
                foreach ($tabs as $preset => [$scope, $notify]) {
                    DB::table('offer_tab_owners')->updateOrInsert(['preset' => $preset, 'user_id' => $user], ['scope' => $scope, 'notify' => $notify, 'created_at' => now()]);
                }
            }
            // Андрей: «Менеджер должен нам» разделена — ему подбор.
            if ($exists(4) && DB::table('offer_tab_owners')->where('user_id', 4)->where('preset', 'paying')->exists()) {
                DB::table('offer_tab_owners')->where('user_id', 4)->where('preset', 'paying')->delete();
                DB::table('offer_tab_owners')->updateOrInsert(['preset' => 'paying_fee', 'user_id' => 4], ['scope' => 'all', 'notify' => true, 'created_at' => now()]);
            }
            // У главного администратора прежняя «Менеджер должен нам» — теперь «Ждём оплату ТС»; подбор — тоже его.
            foreach (DB::table('offer_tab_owners')->where('preset', 'paying')->whereNotIn('user_id', [21])->pluck('user_id') as $user) {
                DB::table('offer_tab_owners')->updateOrInsert(['preset' => 'paying_fee', 'user_id' => $user], ['scope' => 'all', 'notify' => true, 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Расстановку правят в «Настройки → Ответственные»; откатывать нечего.
    }
};
