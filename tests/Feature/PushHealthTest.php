<?php

namespace Tests\Feature;

use App\Push\Device;
use App\Push\Subscription;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Доходит ли пуш (20.10.2026): «Пришло?» после пробного пишется подписке, чужую подписку не проверить и не отметить;
 * владелец видит устройство словами.
 */
class PushHealthTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'http://logistics.xcar.localhost';

    public function test_answer_is_written_to_own_subscription_only(): void
    {
        $tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $ivan = User::create(['name' => 'Иван Петров', 'phone' => '79000000003', 'roles' => [Role::Contractor], 'approved_at' => now()]);
        $sub = Subscription::create(['user_id' => $tanya->id, 'endpoint' => 'https://fcm.example/1', 'p256dh' => 'k', 'auth' => 'a',
            'agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', 'host' => 'logistics.xcar.localhost']);

        $this->actingAs($tanya)->postJson(self::LOG.'/push/confirm', ['endpoint' => $sub->endpoint, 'came' => 0])->assertOk();
        $this->assertNotNull($sub->fresh()->silent_at);
        $this->actingAs($tanya)->postJson(self::LOG.'/push/confirm', ['endpoint' => $sub->endpoint, 'came' => 1])->assertOk();
        $this->assertNotNull($sub->fresh()->confirmed_at);
        $this->assertNull($sub->fresh()->silent_at);

        $this->actingAs($ivan)->postJson(self::LOG.'/push/confirm', ['endpoint' => $sub->endpoint, 'came' => 0])->assertNotFound();
        $this->actingAs($ivan)->postJson(self::LOG.'/push/test', ['endpoint' => $sub->endpoint])->assertNotFound();

        $this->assertSame('Chrome на Mac', $sub->device());
        $this->assertSame('Яндекс Браузер на Android', Device::label('Mozilla/5.0 (Linux; arm_64; Android 16; 2406APNFAG) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.7871.49 YaBrowser/26.8.7.49.00 Mobile Safari/537.36'));
    }
}
