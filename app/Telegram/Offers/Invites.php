<?php

namespace App\Telegram\Offers;

use App\Support\Plural;
use App\Users\Actions\IssueInvite;
use App\Users\Invite;
use App\Users\Role;

/**
 * «3. Пригласи покупателей — будь в топе ⭐️»: сколько покупателей, действующая ссылка — та же, что в кабинете
 * (`/i/{code}`, многоразовая), «📋 Скопировать» (кнопка копирует в буфер), «↗️ Отправить» (выбор чата Telegram), «➕ Новая
 * ссылка» с названием. Группа и что покупатель указывает о себе — в кабинете на сайте.
 */
final class Invites
{
    public const NEW = 'invite:new';

    public function __construct(private OffersBot $bot) {}

    public function show(Subscriber $sub): void
    {
        $user = $sub->user;
        if (! $user->isManager()) {
            $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Ваши ссылки в CRM, раздел «Пользователи»', Keys::toMenu()));

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

    /** «➕ Новая ссылка»: сначала — кому (название видно только менеджеру, в кабинете). */
    public function ask(Subscriber $sub): void
    {
        if (! $sub->user->isManager()) {
            return;
        }
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
        $invite = app(IssueInvite::class)($sub->user, ['label' => $label, 'phone' => false, 'email' => false]);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id, 'Ссылка готова', Keys::toMenu()));
        $this->card($sub, $invite);
        $sub->moveTo(Subscriber::MENU);

        return true;
    }

    private function card(Subscriber $sub, Invite $invite): void
    {
        $url = $invite->url();
        $came = $invite->buyers()->count();
        $share = 'https://t.me/share/url?'.http_build_query(['url' => $url, 'text' => $invite->message()]);
        $this->bot->quietly(fn () => $this->bot->say($sub->chat_id,
            '<b>'.e($invite->title()).'</b>'.($came ? ', пришло '.$came : '')."\n<code>".e($url).'</code>',
            Keys::inline([
                [['text' => '📋 Скопировать', 'copy_text' => ['text' => $url]], ['text' => '↗️ Отправить', 'url' => $share]],
                [['text' => '➕ Новая ссылка', 'callback_data' => self::NEW]],
            ])));
    }
}
