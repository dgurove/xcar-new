<?php

namespace App\Notifications\Console;

use App\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendDigest extends Command
{
    protected $signature = 'notifications:digest';

    protected $description = 'Утреннее письмо с непрочитанными уведомлениями за сутки';

    public function handle(): int
    {
        $sent = 0;
        User::whereNotNull('email')->whereHas('notifications', fn ($q) => $q->whereNull('read_at')->where('created_at', '>=', now()->subDay()))
            ->with(['notifications' => fn ($q) => $q->whereNull('read_at')->where('created_at', '>=', now()->subDay())->latest()])
            ->each(function (User $user) use (&$sent) {
                if (! $user->wantsMail() || ! $user->wantsDigest()) {
                    return;
                }
                Mail::send('mail.digest', ['user' => $user, 'items' => $user->notifications], function ($m) use ($user) {
                    $m->to($user->email, $user->name)->subject('Непрочитанное за сутки — '.config('app.name'));
                });
                $sent++;
            });
        $this->line("писем: {$sent}");

        return self::SUCCESS;
    }
}
