<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Users\User;
use App\Vendors\Vendor;

/**
 * Строка ленты уведомлений видом капсулы (18.11.2026): лицо (аватар того, кто действовал, логотип вендора или значок вида),
 * машина первой строкой, ниже — что. Новые строки несут `car`, `brief`, `actor`, `vendor`, `icon` (`Notice::toArray`); у
 * строк до 18.11 их нет — машина по номеру предложения (`data.offer`), лицо — по имени в начале заголовка, значок — по адресу.
 * Всё — несколькими запросами на страницу ленты (`prime`), а не на строку. Память — на запрос (`scoped`: воркер Octane живёт
 * дольше запроса).
 */
final class NoticeFaces
{
    /** @var array<int, ?User> */
    private array $users = [];

    /** @var array<int, ?Vendor> */
    private array $vendors = [];

    /** @var array<string, string> номер предложения → «Марка Модель, год» */
    private array $cars = [];

    /** @var array<string, User> имя → человек (старые строки без `actor`) */
    private array $names = [];

    private static function bag(): self
    {
        app()->scopedIf(self::class);

        return app(self::class);
    }

    /** @param iterable<\Illuminate\Notifications\DatabaseNotification> $items */
    public static function prime(iterable $items): void
    {
        $users = $vendors = $offers = $names = [];
        foreach ($items as $item) {
            $d = $item->data;
            ($id = $d['actor'] ?? null) && $users[] = (int) $id;
            ($id = $d['vendor'] ?? null) && $vendors[] = (int) $id;
            if (empty($d['car']) && ! empty($d['offer'])) {
                $offers[] = (string) $d['offer'];
            }
            if (empty($d['actor']) && empty($d['vendor']) && ($name = self::nameIn((string) ($d['title'] ?? '')))) {
                $names[] = $name;
            }
        }
        $bag = self::bag();
        $users = array_diff(array_unique($users), array_keys($bag->users));
        $vendors = array_diff(array_unique($vendors), array_keys($bag->vendors));
        $offers = array_diff(array_unique($offers), array_keys($bag->cars));
        $names = array_diff(array_unique($names), array_keys($bag->names));
        if ($users) {
            $bag->users += User::whereIn('id', $users)->get()->keyBy('id')->all() + array_fill_keys($users, null);
        }
        if ($vendors) {
            $bag->vendors += Vendor::whereIn('id', $vendors)->get()->keyBy('id')->all() + array_fill_keys($vendors, null);
        }
        if ($offers) {
            foreach (Offer::withoutGlobalScopes()->whereIn('number', array_map('intval', $offers))->with('car.brand', 'car.model')->get() as $o) {
                $bag->cars[(string) $o->number] = $o->titleWithYear();
            }
        }
        if ($names) {
            foreach (User::whereIn('name', $names)->get() as $u) {
                $bag->names[$u->name] ??= $u;
            }
        }
    }

    /** Имя в начале заголовка старой строки: «Роман Корабельников: …», «Роман Корабельников берёт …». */
    private static function nameIn(string $title): ?string
    {
        return preg_match('/^([А-ЯЁA-Z][а-яёa-z-]+ [А-ЯЁA-Z][а-яёa-z-]+)(?::| берёт )/u', $title, $m) ? $m[1] : null;
    }

    /**
     * Что рисовать: машина, «что», текст ниже, лицо, значок вида.
     *
     * @return array{car: string, what: ?string, more: ?string, user: ?User, vendor: ?Vendor, icon: string, important: bool}
     */
    public static function row(array $data): array
    {
        $bag = self::bag();
        $title = (string) Notice::glue($data['title'] ?? '');
        $text = $data['text'] ?? null;
        $car = $data['car'] ?? (isset($data['offer']) ? ($bag->cars[(string) $data['offer']] ?? null) : null);
        if ($car && empty($data['car'])) {
            // Старая строка: «Имя: сообщение по № N» — сообщение чата; иначе заголовок без машины.
            $chat = preg_match('/^(.+?): (?:сообщение|обращение)\b/u', $title, $m) === 1;
            $what = $chat ? $m[1].($text ? ': '.$text : '') : (trim(str_replace($car, '', $title), " \u{00A0}:—–-,") ?: $text);
            $more = $chat || ! $text || self::repeats((string) $what, $text) ? null : $text;
        } elseif ($car) {
            $what = $data['brief'] ?? $title;
            $more = $text && ! self::repeats((string) $what, $text) ? $text : null;
        } else {
            $what = $text;
            $more = null;
        }
        $user = self::user($data) ?? (($name = self::nameIn($title)) && empty($data['vendor']) ? ($bag->names[$name] ?? null) : null);

        return [
            'car' => $car ?: $title, 'what' => $what, 'more' => $more, 'user' => $user,
            'vendor' => $user ? null : self::vendor($data), 'icon' => $data['icon'] ?? self::iconOf($data), 'important' => ! empty($data['important']),
        ];
    }

    /**
     * Текст повторяет «что»: письмо без шаблона («Т-Страхование: ПТС во вложении…») или шаблон, которым текст и начинается
     * («АльфаСтрахование: ГОТС поступили на реализацию» — «ГОТС поступили на реализацию. Коллеги…»).
     */
    private static function repeats(string $what, string $text): bool
    {
        $tail = trim(str_contains($what, ': ') ? mb_substr($what, mb_strpos($what, ': ') + 2) : $what);
        $text = preg_replace('/^(?:Добрый день|Здравствуйте)[!,.]?\s*/u', '', trim($text));

        return $tail !== '' && (str_starts_with(mb_strtolower($text), mb_strtolower(mb_substr($tail, 0, 25))) || str_contains($what, mb_substr($text, 0, 40)));
    }

    /** Заголовок дня в ленте: «Сегодня», «Вчера», «16.11», в другом году — «16.11.2025». */
    public static function day(\DateTimeInterface $at): string
    {
        $d = \Illuminate\Support\Carbon::instance($at)->timezone('Europe/Moscow');
        $now = now('Europe/Moscow');

        return match (true) {
            $d->isSameDay($now) => 'Сегодня',
            $d->isSameDay($now->copy()->subDay()) => 'Вчера',
            $d->year === $now->year => $d->format('d.m'),
            default => $d->format('d.m.Y'),
        };
    }

    /** Значок старой строки по адресу: чат, деньги, письма, сделка. */
    private static function iconOf(array $data): string
    {
        $path = (string) ($data['subject'] ?? $data['href'] ?? '');

        return match (true) {
            str_contains($path, '/chats') => 'chat',
            str_contains($path, '/money') => 'wallet',
            str_contains($path, '/mail') || str_contains($path, 'letters') => 'mail',
            str_contains($path, '/deals') => 'deal',
            str_contains($path, 'logistics') || str_contains($path, '/transports') => 'cat-car',
            str_contains($path, '/offers') => 'cat-car',
            default => 'bell',
        };
    }

    public static function user(array $data): ?User
    {
        $id = (int) ($data['actor'] ?? 0);
        $bag = self::bag();

        return $id ? (array_key_exists($id, $bag->users) ? $bag->users[$id] : ($bag->users[$id] = User::find($id))) : null;
    }

    public static function vendor(array $data): ?Vendor
    {
        $id = (int) ($data['vendor'] ?? 0);
        $bag = self::bag();

        return $id ? (array_key_exists($id, $bag->vendors) ? $bag->vendors[$id] : ($bag->vendors[$id] = Vendor::find($id))) : null;
    }
}
