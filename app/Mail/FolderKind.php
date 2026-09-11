<?php

namespace App\Mail;

use App\Cars\HasLabels;

/** Роль папки — по атрибутам LIST, не по имени: имена у mail.ru русские и в modified UTF-7. */
enum FolderKind: string
{
    use HasLabels;

    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Spam = 'spam';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Входящие',
            self::Sent => 'Отправленные',
            self::Drafts => 'Черновики',
            self::Trash => 'Корзина',
            self::Spam => 'Спам',
            self::Custom => 'Папка',
        };
    }

    public function isNoise(): bool
    {
        return in_array($this, [self::Trash, self::Spam, self::Drafts], true);
    }

    public static function fromImapAttributes(array $attributes, string $path = ''): self
    {
        if (strtoupper($path) === 'INBOX') {
            return self::Inbox;
        }
        $normalized = array_map(fn ($a) => strtolower(ltrim((string) $a, '\\')), $attributes);
        foreach (self::cases() as $case) {
            if ($case !== self::Custom && $case !== self::Inbox && in_array($case->value, $normalized, true)) {
                return $case;
            }
        }
        if (in_array('junk', $normalized, true)) {
            return self::Spam;
        }

        return self::Custom;
    }
}
