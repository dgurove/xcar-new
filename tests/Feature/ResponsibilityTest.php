<?php

namespace Tests\Feature;

use App\Notifications\Notice;
use App\Offers\Responsibility;
use App\Offers\TabOwner;
use App\Push\WebPushChannel;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Уведомления сотруднику — только по его ответственности (26.11.2026, `Responsibility::wants`): не своё — строкой ленты без пуша
 * и всплывашки; «только горящее» — только о горящем; без «Ответственных» и главному администратору — как раньше. Ошибка здесь
 * молчит: уведомления просто не приходят.
 */
class ResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    private function notice(?array $tabs, bool $urgent = false): Notice
    {
        return new class($tabs, $urgent) extends Notice
        {
            public function __construct(private ?array $where, private bool $hot) {}

            public function title(): string
            {
                return 'Проверка';
            }

            public function label(): string
            {
                return 'Проверка';
            }

            public function href(): string
            {
                return '/offers';
            }

            public function tabs(): ?array
            {
                return $this->where;
            }

            public function urgent(): bool
            {
                return $this->hot;
            }
        };
    }

    public function test_staff_gets_push_only_for_own_tabs(): void
    {
        $katya = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin, Role::Moderator], 'approved_at' => now()]);
        $owner = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::Owner], 'approved_at' => now()]);
        $free = User::create(['name' => 'Без вкладок', 'phone' => '79000000040', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $alina = User::create(['name' => 'Алина', 'phone' => '79000000021', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        TabOwner::create(['preset' => 'pickup_assign', 'user_id' => $katya->id, 'scope' => 'all', 'notify' => true]);
        TabOwner::create(['preset' => 'pickup_moving', 'user_id' => $katya->id, 'scope' => 'urgent', 'notify' => true]);
        TabOwner::create(['preset' => 'nofloor', 'user_id' => $katya->id, 'scope' => 'all', 'notify' => false]);
        TabOwner::create(['preset' => 'check', 'user_id' => $alina->id, 'scope' => 'all', 'notify' => true]);
        TabOwner::create(['preset' => 'all_deals', 'user_id' => $alina->id, 'scope' => 'all', 'notify' => true]);

        $this->assertTrue(Responsibility::wants($katya, $this->notice(['pickup_assign'])));
        $this->assertFalse(Responsibility::wants($katya, $this->notice(['pickup_moving'])), 'не горящее во «только горящем»');
        $this->assertTrue(Responsibility::wants($katya, $this->notice(['pickup_moving'], urgent: true)));
        $this->assertFalse(Responsibility::wants($katya, $this->notice(['nofloor'])), 'своя вкладка без уведомлений');
        $this->assertFalse(Responsibility::wants($katya, $this->notice(['check'])));
        $this->assertTrue(Responsibility::wants($katya, $this->notice(null)), 'не о строке вкладки — как раньше');
        $this->assertTrue(Responsibility::wants($owner, $this->notice(['check'])), 'главный администратор — всё');
        $this->assertTrue(Responsibility::wants($free, $this->notice(['check'])), 'без «Ответственных» — как раньше');
        $this->assertTrue(Responsibility::wants($alina, $this->notice(['check'])));
        // Вкладка ожидания без подписанных — тем, кто отвечает за раздел («Все сделки»).
        $this->assertTrue(Responsibility::wants($alina, $this->notice(['agree'])));
        $this->assertFalse(Responsibility::wants($katya, $this->notice(['agree'])));
        // Ответственные есть, но без уведомлений — тихо, разделу не перекладываем.
        TabOwner::create(['preset' => 'payout', 'user_id' => $katya->id, 'scope' => 'all', 'notify' => false]);
        $this->assertFalse(Responsibility::wants($alina, $this->notice(['payout'])));
        // «Уведомлять обо всём» (27.11.2026): пуш и Telegram обо всём, а красные счётчики — прежние, своих вкладок.
        TabOwner::create(['preset' => Responsibility::EVERYTHING, 'user_id' => $katya->id, 'scope' => 'all', 'notify' => true]);
        TabOwner::forget();
        $this->assertTrue(Responsibility::wants($katya, $this->notice(['check'])), 'обо всём — и не своя вкладка');
        $this->assertTrue(Responsibility::wants($katya, $this->notice(['pickup_moving'])), 'обо всём — и не горящее');
        $this->assertNotContains(Responsibility::EVERYTHING, TabOwner::presetsOf($katya));
        TabOwner::where('preset', Responsibility::EVERYTHING)->delete();
        TabOwner::forget();
        // Без «Ответственных» — все красные счётчики колеса, как раньше.
        [, $badges] = Responsibility::wheel($free, ['bill' => 2, 'bill_our' => 2]);
        $this->assertSame(array_diff_key(\App\Http\Admin\OfferController::badges(), ['all_deals' => true]), $badges);
        [, $mine] = Responsibility::wheel($alina, ['bill' => 2, 'bill_our' => 2, 'check' => 1, 'check_our' => 1]);
        $this->assertSame(['check' => 'check_mine'], array_intersect_key($mine, ['bill' => 1, 'check' => 1]));

        // Не своё — только строка ленты, без пуша и всплывашки.
        $notice = $this->notice(['check']);
        $this->assertSame(['database'], $notice->via($katya));
        $this->assertTrue($notice->mutedFor($katya));
        $this->assertContains(WebPushChannel::class, $this->notice(['pickup_assign'])->via($katya));
    }

    /** «Поставщику не заплачено» не тревожит (26.11.2026): тревога и горящее — только когда покупателя нет. */
    public function test_supplier_unpaid_raises_no_alarm(): void
    {
        $this->assertStringNotContainsString('supplier_paid_at', \App\Offers\Binding::buyerlessSql());
        $this->assertSame(\App\Offers\Binding::buyerlessSql(), Responsibility::hotSql());
    }
}
