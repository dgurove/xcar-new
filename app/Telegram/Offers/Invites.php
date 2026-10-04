<?php

namespace App\Telegram\Offers;

use App\Support\Plural;
use App\Users\Actions\IssueInvite;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;

/**
 * «3. Пригласи покупателей — будь в топе ⭐️»: сколько покупателей, действующая ссылка — та же, что в кабинете
 * (`/i/{code}`, многоразовая), «📋 Скопировать» (кнопка копирует в буфер), «↗️ Отправить» (выбор чата Telegram), «➕ Новая
 * ссылка» с названием. Группа и что покупатель указывает о себе — в кабинете на сайте.
 * Админу — как в CRM «Пользователи → Ссылки»: одноразовая ссылка менеджеру (3 дня) или ссылка покупателю от имени
 * выбранного менеджера.
 */
final class Invites
{
    public const NEW = 'invite:new';

    public const MANAGER = 'invite:manager';

    public const BUYER = 'invite:buyer';

    public const FOR = 'invite:for:';

    public function __construct(private OffersBot $bot) {}

    public function show(Subscriber $sub): void
    {
        $user = $sub->user;
        if ($user->isAdmin()) {
            $this->admin($sub);

            return;
        }
        $n = $user->buyers()->count();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            $n ? 'У вас '.$n.' '.Plural::of($n, ['покупатель', 'покупателя', 'покупателей']) : 'Покупателей пока нет', Keys::toMenu()));
        $invite = Invite::where('manager_id', $user->id)->where('role', Role::Buyer)->orderByDesc('id')->get()->first(fn (Invite $i) => $i->isActive());
        $invite ? $this->card($sub, $invite)
            : $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Ссылки для покупателей ещё нет', Keys::inline([[['text' => '➕ Создать ссылку', 'callback_data' => self::NEW]]])));
        $sub->moveTo(Subscriber::MENU);
    }

    /** Нажатие под сообщением раздела: новая ссылка, менеджеру, покупателю — от какого менеджера. */
    public function press(Subscriber $sub, string $data): void
    {
        $admin = $sub->user->isAdmin();
        match (true) {
            $data === self::NEW && ! $admin => $this->ask($sub),
            $data === self::MANAGER && $admin => $this->managerLink($sub),
            $data === self::BUYER && $admin => $this->pickManager($sub),
            str_starts_with($data, self::FOR) && $admin => $this->ask($sub, (int) substr($data, strlen(self::FOR))),
            default => null,
        };
    }

    /** Админ: сколько менеджеров и покупателей на площадке, две кнопки — кого звать, и последняя живая ссылка менеджеру. */
    private function admin(Subscriber $sub): void
    {
        $managers = User::withRole(Role::Manager)->count();
        $buyers = User::withRole(Role::Buyer)->count();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            $managers.' '.Plural::of($managers, ['менеджер', 'менеджера', 'менеджеров']).', '.$buyers.' '.Plural::of($buyers, ['покупатель', 'покупателя', 'покупателей']),
            Keys::toMenu()));
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Кого пригласить?', Keys::inline([
            [['text' => '➕ Менеджера', 'callback_data' => self::MANAGER], ['text' => '➕ Покупателя', 'callback_data' => self::BUYER]],
        ])));
        $sub->moveTo(Subscriber::MENU);
    }

    /** Ссылка менеджеру — одноразовая и на три дня, как в форме CRM. */
    private function managerLink(Subscriber $sub): void
    {
        $invite = app(IssueInvite::class)($sub->user, ['role' => Role::Manager->value, 'expires_at' => now()->addDays(3)]);
        $this->card($sub, $invite);
    }

    /** Покупатель приходит к менеджеру: сначала — к какому. */
    private function pickManager(Subscriber $sub): void
    {
        $rows = User::withRole(Role::Manager)->whereNotNull('approved_at')->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $m) => ['text' => $m->shortName(), 'callback_data' => self::FOR.$m->id])->chunk(2)->map(fn ($r) => $r->values()->all())->values()->all();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, $rows ? 'От какого менеджера?' : 'Менеджеров пока нет', $rows ? Keys::inline($rows) : null));
    }

    /** «➕ Новая ссылка»: сначала — кому (название видно в кабинете и CRM). У админа — от имени выбранного менеджера. */
    public function ask(Subscriber $sub, ?int $managerId = null): void
    {
        if ($managerId && ! User::withRole(Role::Manager)->whereKey($managerId)->exists()) {
            return;
        }
        $sub->forceFill(['payload' => $managerId ? ['manager_id' => $managerId] : null])->save();
        $sub->moveTo(Subscriber::INVITE);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Кому ссылка? Напишите имя или компанию', Keys::reply([[Keys::NO_LABEL], [Keys::MENU]])));
    }

    /** Ответ на «Кому ссылка?»; false — не текст, пусть разговор скажет «нет такого варианта». */
    public function label(Subscriber $sub, string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $label = $text === Keys::NO_LABEL ? null : mb_substr($text, 0, 60);
        $managerId = $sub->user->isAdmin() ? ($sub->payload['manager_id'] ?? null) : null;
        if ($sub->user->isAdmin() && ! $managerId) {
            return false;
        }
        $invite = app(IssueInvite::class)($sub->user, ['label' => $label, 'phone' => false, 'email' => false]
            + ($managerId ? ['role' => Role::Buyer->value, 'manager_id' => $managerId] : []));
        $sub->forceFill(['payload' => null])->save();
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Ссылка готова', Keys::toMenu()));
        $this->card($sub, $invite);
        $sub->moveTo(Subscriber::MENU);

        return true;
    }

    private function card(Subscriber $sub, Invite $invite): void
    {
        $url = $invite->url();
        $came = $invite->buyers()->count();
        // Ссылке менеджеру — до какого она живёт; покупателю админа — чей покупатель.
        $tail = match (true) {
            $invite->expires_at !== null => ', до '.$invite->expires_at->translatedFormat('j M, H:i'),
            $invite->manager_id && $invite->manager_id !== $sub->user_id => ', от '.$invite->manager?->shortName(),
            default => '',
        };
        $share = 'https://t.me/share/url?'.http_build_query(['url' => $url, 'text' => $invite->message()]);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            '<b>'.e($invite->title()).'</b>'.e($tail).($came ? ', пришло '.$came : '')."\n<code>".e($url).'</code>',
            Keys::inline([
                [['text' => '📋 Скопировать', 'copy_text' => ['text' => $url]], ['text' => '↗️ Отправить', 'url' => $share]],
                ...($sub->user->isAdmin() ? [] : [[['text' => '➕ Новая ссылка', 'callback_data' => self::NEW]]]),
            ])));
    }
}
