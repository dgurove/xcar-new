<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Scope;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Окно письма по ссылке без фрейма (уведомление «напишите письмо») — один раз и только по настоящему переходу (21.11.2026: у
 * Андрея на любой странице CRM всплывало письмо по давней машине — метку продлевали фоновые запросы, её ставила предзагрузка).
 */
class LetterWindowFlashTest extends TestCase
{
    use RefreshDatabase;

    public function test_letter_window_opens_once_and_not_from_prefetch(): void
    {
        config(['xcar.mercure.hub' => '']);
        Account::create(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.test', 'scope' => Scope::Offers,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $crm = 'http://crm.xcar.localhost';
        $compose = '/work/mail/new?template=5';
        $opens = fn ($html) => str_contains($html, 'data-window-url-value="'.e($compose).'"');

        // Предзагрузка ссылки — метки нет.
        $this->actingAs($admin)->withHeaders(['Sec-Purpose' => 'prefetch', 'Referer' => $crm.'/offers'])->get($crm.$compose)
            ->assertRedirect($crm.'/work/mail?compose='.urlencode($compose));
        $this->assertNull(session('letters'));

        // Настоящий переход — окно на странице, откуда пришли, один раз.
        $this->flushHeaders();
        $this->actingAs($admin)->withHeaders(['Referer' => $crm.'/work/mail'])->get($crm.$compose)->assertRedirect($crm.'/work/mail');
        $this->flushHeaders();
        $this->assertTrue($opens($this->actingAs($admin)->get($crm.'/work/mail')->getContent()));
        // Фоновый запрос (значок) не возвращает забранную метку, следующая страница окна уже не открывает.
        $this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get($crm.'/live/badges');
        $this->flushHeaders();
        $this->assertFalse($opens($this->actingAs($admin)->get($crm.'/work/mail')->getContent()));
    }
}
