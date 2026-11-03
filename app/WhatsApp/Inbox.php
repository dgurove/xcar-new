<?php

namespace App\WhatsApp;

use App\Users\User;
use App\WhatsApp\Actions\LinkWhatsApp;

/**
 * Входящие из службы в Цюрихе — только сообщения с кодом привязки (остальное там же отброшено: это личный WhatsApp
 * владельца). Код верный — привязка и ответ «Готово» с приглашением в группу, устарел — просьба нажать ещё раз.
 */
final class Inbox
{
    public function __construct(private LinkWhatsApp $link, private Sender $sender) {}

    /** @param array{id: int, jid: string, phone?: string, name?: string, text?: string, at?: int} $row */
    public function handle(array $row): void
    {
        if (! preg_match(LinkCode::PATTERN, (string) ($row['text'] ?? ''), $m) || ($row['jid'] ?? '') === '') {
            return;
        }
        // Ключ ответа — от чата и времени, а не от номера строки: базу службы могут завести заново.
        $key = 'reply:'.$row['jid'].':'.($row['at'] ?? $row['id']);
        $user = LinkCode::owner($m[1]);
        if ($user === null) {
            $this->sender->reply($row['jid'], 'Ссылка устарела — нажмите «Подключить WhatsApp» в XCar ещё раз', $key);

            return;
        }
        ($this->link)($user, $row['jid'], preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?: null);
        $this->sender->reply($row['jid'], $this->welcome($user), $key);
    }

    private function welcome(User $user): string
    {
        $group = $this->sender->group();

        return implode("\n", array_filter([
            "Готово, {$user->firstName()}: WhatsApp подключён к XCar",
            'Сюда придут предложения, открытые только вам, и просьбы оценить',
            Sender::open() && $group['invite'] ? 'Все новые — в группе «'.($group['name'] ?: 'XCar | Предложения').'»: '.$group['invite'] : null,
        ]));
    }
}
