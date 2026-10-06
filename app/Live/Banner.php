<?php

namespace App\Live;

use App\Notifications\Notice;
use App\Notifications\NoticeLink;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

/**
 * Карточка уведомления для живого канала: готовая разметка `x-ui.banner` и то, что нужно клиенту, чтобы решить, где и
 * как её показать (banner_controller). surface — приложение, где она живёт: `park` — только на парковке, `crm` — только
 * в CRM, пусто — везде, кроме парковки.
 *
 * @phpstan-type Payload array{id: string, subject: ?string, important: bool, surface: ?string, hrefs: array<string, string>, html: string}
 */
final class Banner
{
    /** @return Payload */
    public static function of(Notice $notice, string $id): array
    {
        return self::make($id, $notice->title(), $notice->text(), $notice->href(), $notice->actor(), $notice->icon(),
            $notice->important(), $notice->subject(), $notice->surface());
    }

    /** Событие без уведомления в ленте (письмо в ящик, файлы из письма): тихо, уходит само. @return Payload */
    public static function plain(string $title, ?string $href, string $icon, ?Surface $surface): array
    {
        return self::make((string) Str::uuid(), $title, null, $href, null, $icon, false, null, $surface);
    }

    /** Сообщение чата — важное, тема — чат: следующее сообщение того же чата заменяет карточку. @return Payload */
    public static function chat(string $from, string $text, string $href, ?User $author, int $chat): array
    {
        return self::make((string) Str::uuid(), $from, $text, $href, $author, 'chat', true, '/account/chats/'.$chat, null);
    }

    /** @return Payload */
    private static function make(string $id, string $title, ?string $text, ?string $href, ?User $user, string $icon, bool $important, ?string $subject, ?Surface $surface): array
    {
        $html = Blade::render('<x-ui.banner :id="$id" :title="$title" :text="$text" :href="$href" :user="$user" :icon="$icon" :important="$important" :subject="$subject"/>',
            compact('id', 'title', 'text', 'href', 'user', 'icon', 'important', 'subject'));

        // Адрес под каждое приложение: клиент ставит свой (путь сайта в CRM — «Такой страницы нет»).
        $hrefs = $href === null ? [] : array_combine(
            array_map(fn (Surface $s) => $s->value, Surface::cases()),
            array_map(fn (Surface $s) => NoticeLink::for(['href' => $href, 'subject' => $subject], $s), Surface::cases()),
        );

        return ['id' => $id, 'subject' => $subject, 'important' => $important, 'surface' => $surface?->value, 'hrefs' => $hrefs, 'html' => trim($html)];
    }
}
