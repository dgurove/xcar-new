<?php

namespace Tests\Feature;

use App\Support\Settings;
use App\Users\Access\AccessCode;
use App\Users\Access\Device;
use App\Users\Access\Lock;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Замок менеджера (07.10.2026): за окном сервер не отдаёт ни страницы, ни фрагмента; код один раз, пять промахов — и
 * он погашен; без подписи ключом браузера код не принимается; пароль закрыт только пока замок включён. Ошибка здесь
 * молчалива — закупочные цены уйдут постороннему, и никто не заметит, — поэтому тест.
 */
class ManagerLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Замок пишет в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        config(['xcar.telegram.token' => 'test-token', 'xcar.telegram.username' => 'xcar_test_bot', 'xcar.mercure.hub' => '']);
        $id = 70;
        Http::fake(['api.telegram.org/*' => function () use (&$id) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$id]]);
        }]);
        Settings::put(Lock::SETTING, true);
        // Метка устройства и код — в cookie; JSON-запросы теста без этого cookie не шлют.
        $this->withCredentials();
    }

    public function test_frozen_manager_gets_no_data_and_open_one_does(): void
    {
        $manager = $this->manager();
        $this->device($manager, now()->subMinute());

        $page = $this->actingAs($manager)->withCookie(Lock::COOKIE, $manager->id.':tok')->get('/offers', ['Accept' => 'text/html']);
        $page->assertStatus(423)->assertSee('id="lock"', false)->assertDontSee('id="catalog"', false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->withCookie(Lock::COOKIE, $manager->id.':tok')->get('/live/badges', ['Accept' => 'text/vnd.turbo-stream.html'])->assertStatus(423);

        Device::where('user_id', $manager->id)->update(['verified_until' => now()->addMinutes(10)]);
        $this->withCookie(Lock::COOKIE, $manager->id.':tok')->get('/offers', ['Accept' => 'text/html'])->assertOk();
    }

    public function test_session_of_replaced_device_is_logged_out(): void
    {
        $manager = $this->manager();
        $this->device($manager, now()->addMinutes(10));

        $this->actingAs($manager)->withCookie(Lock::COOKIE, $manager->id.':old')->get('/offers', ['Accept' => 'text/html'])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_code_needs_digits_and_device_key_and_dies_after_five_misses(): void
    {
        $manager = $this->manager();
        $session = $this->actingAs($manager)->withCookie(Lock::COOKIE, $manager->id.':tok');
        [$pem, $jwk] = $this->key();
        $this->device($manager, now()->subMinute(), $jwk);

        $nonce = $session->postJson('/lock/code')->assertOk()->json('nonce');
        $code = $this->sentCode();
        // Код принимает только браузер с его cookie — клиент теста её не помнит, кладём сами.
        $this->withCookie(Lock::CODE_COOKIE, (string) AccessCode::latest('id')->value('id'));
        // Верные цифры, но подпись чужим ключом — нет.
        [, $foreign] = $this->key();
        $this->postJson('/lock/verify', ['code' => $code, 'key' => json_encode($foreign), 'sig' => $this->sign($pem, $nonce)])->assertStatus(422);
        // Верные цифры и ключ этого устройства — окно открыто.
        $this->postJson('/lock/verify', ['code' => $code, 'key' => json_encode($jwk), 'sig' => $this->sign($pem, $nonce)])->assertOk();
        $this->assertTrue(Device::live($manager)->isOpen());
        $this->assertNotNull(AccessCode::latest('id')->first()->used_at);

        // Новый код: пять промахов — погашен, верные цифры после этого не помогают.
        Device::where('user_id', $manager->id)->update(['verified_until' => now()->subMinute()]);
        $this->travel(AccessCode::RESEND + 1)->seconds();
        $nonce = $this->postJson('/lock/code', ['again' => 1])->assertOk()->json('nonce');
        $code = $this->sentCode();
        $this->withCookie(Lock::CODE_COOKIE, (string) AccessCode::latest('id')->value('id'));
        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, AccessCode::ATTEMPTS) as $i) {
            $this->postJson('/lock/verify', ['code' => $wrong, 'key' => json_encode($jwk), 'sig' => $this->sign($pem, $nonce)]);
        }
        $this->postJson('/lock/verify', ['code' => $code, 'key' => json_encode($jwk), 'sig' => $this->sign($pem, $nonce)])->assertStatus(410);
    }

    public function test_password_is_closed_only_while_lock_is_on(): void
    {
        $manager = $this->manager();
        $manager->forceFill(['password' => 'secret-pass'])->save();

        $this->post('/login', ['login' => '79000000002', 'password' => 'secret-pass'])->assertSessionHasErrors('login');
        $this->assertGuest();

        Settings::put(Lock::SETTING, false);
        $this->post('/login', ['login' => '79000000002', 'password' => 'secret-pass']);
        $this->assertAuthenticatedAs($manager);
    }

    private function manager(): User
    {
        $user = User::create(['name' => 'Менеджер Тестов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $user->forceFill(['telegram_chat_id' => 555, 'telegram_linked_at' => now()->subDay()])->save();

        return $user;
    }

    private function device(User $user, $until, ?array $key = null): Device
    {
        return Device::create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', 'tok'), 'public_key' => $key ?? $this->key()[1], 'label' => 'Safari, iPhone',
            'topic' => 'lock/test', 'verified_until' => $until,
        ]);
    }

    private function sentCode(): string
    {
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage'))->last();
        preg_match('~<code>(\d{6})</code>~', (string) ($sent['text'] ?? ''), $m);

        return $m[1] ?? '';
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: array} */
    private function key(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($key)['ec'];
        $b64 = fn ($bin) => rtrim(strtr(base64_encode(str_pad($bin, 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');

        return [$key, ['kty' => 'EC', 'crv' => 'P-256', 'x' => $b64($ec['x']), 'y' => $b64($ec['y'])]];
    }

    /** Подпись как у WebCrypto: r‖s по 32 байта, base64url. */
    private function sign($key, string $nonce): string
    {
        openssl_sign($nonce, $der, $key, OPENSSL_ALGO_SHA256);
        $pos = 2;
        $parts = [];
        foreach ([0, 1] as $_) {
            $len = ord($der[$pos + 1]);
            $parts[] = str_pad(ltrim(substr($der, $pos + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return rtrim(strtr(base64_encode(implode('', $parts)), '+/', '-_'), '=');
    }
}
