<?php

namespace Tests\Feature;

use App\Notifications\Delivery;
use App\Notifications\MoneyNotice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Журнал доставки (30.10.2026): каждый канал уведомления — строкой с видом и подписью, пуш без подписок — «некуда»;
 * переход по метке `nt` отмечает открытым только своё уведомление у самого человека и убирает метку из адреса. Ошибка
 * здесь молчалива: журнал перестанет писаться — статистика «Доставки» просто опустеет.
 */
class NoticeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    public function test_channels_are_logged_and_open_is_marked_by_owner_only(): void
    {
        // Отметка открытия пишет в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        config(['xcar.vapid.public' => 'test']);
        $admin = User::create(['name' => 'Админ Тестов', 'phone' => '79000000011', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $other = User::create(['name' => 'Другой Админ', 'phone' => '79000000012', 'roles' => [Role::Admin], 'approved_at' => now()]);

        $admin->notifyNow(MoneyNotice::gatewayDown('тест'));

        $rows = Delivery::where('user_id', $admin->id)->get()->keyBy('channel');
        $this->assertSame('sent', $rows['feed']->status);
        $this->assertSame('skipped', $rows['push']->status);
        $this->assertSame('MoneyNotice:gateway-down', $rows['feed']->kind);
        $this->assertSame('ЮKassa не принимает', $rows['feed']->label);
        $id = $rows['feed']->notice_id;
        $this->assertSame($admin->notifications()->value('id'), $id);

        // Чужая метка ничего не отмечает, но из адреса уходит.
        $this->actingAs($other)->get(self::CRM.'/work/money?tab=links&nt='.$id.'.push')->assertRedirect(self::CRM.'/work/money?tab=links');
        $this->assertSame(0, Delivery::whereNotNull('opened_at')->count());

        $this->actingAs($admin)->get(self::CRM.'/work/money?nt='.$id.'.push')->assertRedirect(self::CRM.'/work/money');
        $this->assertSame(['push'], Delivery::where('user_id', $admin->id)->pluck('opened_via')->unique()->values()->all());
        $this->actingAs($admin)->get(self::CRM.'/work/money?nt=мусор')->assertRedirect(self::CRM.'/work/money');

        $this->actingAs($admin)->get(self::CRM.'/settings/deliveries')->assertOk()->assertSee('ЮKassa не принимает');
        $this->actingAs($admin)->get(self::CRM.'/settings/deliveries?view=people&peek='.$admin->id)->assertOk()->assertSee('открыл, пуш');
    }
}
