<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Senders;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * С каких ящиков пишут (21.11.2026, владелец): управляющий парковки — только storage@, модератор CRM — только deal@, админ —
 * с обоих. Ящик решает человек, а не сторона экрана (`Senders`).
 */
class MailSendersTest extends TestCase
{
    use RefreshDatabase;

    private function box(string $slug, Scope $scope, ?int $replyTo = null): Account
    {
        return Account::create(['slug' => $slug, 'title' => $slug, 'email' => "{$slug}@xcar.test", 'scope' => $scope, 'reply_account_id' => $replyTo,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
    }

    public function test_who_writes_from_which_box(): void
    {
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $deal = $this->box('deal', Scope::Offers);
        $this->box('offer', Scope::Offers, $deal->id);
        $this->box('storage', Scope::Park);

        // Админ, что ведёт и парковку: на park.xcar его пускает роль парковки.
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Модератор', 'phone' => '79000000002', 'roles' => [Role::Moderator], 'access' => ['crm.mail'], 'approved_at' => now()]);
        $parking = User::create(['name' => 'Управляющий', 'phone' => '79000000003', 'roles' => [Role::Parking], 'approved_at' => now()]);
        $bare = User::create(['name' => 'Без почты', 'phone' => '79000000004', 'roles' => [Role::Moderator], 'approved_at' => now()]);

        // offer@ только принимает — писать с него нельзя никому.
        $this->assertSame(['deal', 'storage'], Senders::for($admin)->pluck('slug')->sort()->values()->all());
        $this->assertSame(['deal'], Senders::for($moderator)->pluck('slug')->all());
        $this->assertSame(['storage'], Senders::for($parking)->pluck('slug')->all());
        $this->assertSame([], Senders::for($bare)->all());

        $letter = fn (string $account) => ['account' => $account, 'to' => 'implementation@alfastrah.ru', 'subject' => 'Тест', 'body' => '<div>Текст</div>'];
        $sent = fn () => Message::where('direction', 'out')->count();

        // Модератор из CRM: с storage@ — нет, с deal@ — да.
        $this->actingAs($moderator)->post('http://crm.xcar.localhost/work/mail', $letter('storage'))->assertSessionHasErrors('account');
        $this->assertSame(0, $sent());
        $this->actingAs($moderator)->post('http://crm.xcar.localhost/work/mail', $letter('deal'))->assertSessionHasNoErrors();
        $this->assertSame(1, $sent());

        // Управляющий с парковки: с deal@ — нет, с storage@ — да.
        $this->actingAs($parking)->post('http://park.xcar.localhost/mail', $letter('deal'))->assertSessionHasErrors('account');
        $this->assertSame(1, $sent());
        $this->actingAs($parking)->post('http://park.xcar.localhost/mail', $letter('storage'))->assertSessionHasNoErrors();
        $this->assertSame(2, $sent());

        // Админ — с обоих и с любой стороны.
        $this->actingAs($admin)->post('http://crm.xcar.localhost/work/mail', $letter('storage'))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('http://park.xcar.localhost/mail', $letter('deal'))->assertSessionHasNoErrors();
        $this->assertSame(4, $sent());
        $this->assertSame('storage', Message::where('direction', 'out')->orderBy('id')->get()[2]->account->slug);
    }
}
