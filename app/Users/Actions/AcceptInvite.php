<?php

namespace App\Users\Actions;

use App\Media\PhotoIngest;
use App\Users\Events\BuyerJoined;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Регистрация по пригласительной ссылке — единственная на сайте. Покупатель
 * привязывается к менеджеру насовсем, получает доступ сразу и попадает в группу
 * ссылки. Телефон и почта — только те, что менеджер разрешил в ссылке.
 *
 * @param array{name: string, login: string, password: string, phone?: ?string, email?: ?string} $data
 */
final class AcceptInvite
{
    public function __construct(private PhotoIngest $photos) {}

    public function __invoke(Invite $invite, array $data, ?UploadedFile $avatar = null): User
    {
        $user = DB::transaction(function () use ($invite, $data) {
            $user = User::create([
                'name' => trim($data['name']),
                'login' => $data['login'],
                'password' => $data['password'],
                'phone' => $invite->allows('phone') ? ($data['phone'] ?? null) : null,
                'email' => $invite->allows('email') ? ($data['email'] ?? null) : null,
                'role' => Role::Buyer,
                'manager_id' => $invite->manager_id,
                'invite_id' => $invite->id,
                'contact_fields' => $invite->contactFields(),
                'approved_at' => now(),
            ]);
            if ($invite->group_id) {
                $user->groups()->attach($invite->group_id, ['created_at' => now()]);
            }
            $invite->increment('uses_count');

            return $user;
        });

        if ($avatar) {
            // Аватар живёт в 128 px: исходник с телефона не нужен. Не вышло — человек уже в xcar, фото добавит в профиле.
            try {
                $this->photos->fromUpload($user, 'avatar', $avatar, max: 512);
            } catch (\Throwable $e) {
                Log::warning('Аватар при регистрации не принят', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        BuyerJoined::dispatch($user, $invite);

        return $user;
    }
}
