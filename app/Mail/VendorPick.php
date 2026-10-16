<?php

namespace App\Mail;

use App\Mail\Chains\NodeTitle;
use App\Users\User;
use App\Vendors\Actions\BindSender;

/**
 * «Выбрать вендора» у письма, где вендор не узнан (16.10.2026): что знает кнопка — куда слать (`{base}/{ветка}/vendor`),
 * чей адрес, догадка названия для «Новый» и домен, если его можно отдать вендору целиком. Одна дверь для шапки цепочки,
 * заголовка дела в почте, окна письма и разбора письма на парковке. Нет права править вендоров или чужого адреса — null.
 */
final class VendorPick
{
    /** Вендоров правит админ CRM, на парковке — администратор парковки: кнопка и шторка — только им. */
    public static function allowed(?User $user, Scope $scope): bool
    {
        return $user !== null && ($scope === Scope::Park ? $user->isParkAdmin() : $user->canManageCrm());
    }

    /** @return array{url: string, sender: string, name: string, domain: string}|null */
    public static function of(?User $user, Scope $scope, string $base, ?Thread $thread, iterable $letters = [], ?Candidate $candidate = null): ?array
    {
        if (! $thread || ! self::allowed($user, $scope)) {
            return null;
        }
        // Настоящий отправитель — последнее письмо извне (у пересылки сотрудника — из «От:» цитаты), иначе — что
        // запомнила цепочка.
        $last = collect($letters)->filter(fn ($m) => $m instanceof Message && $m->isFromVendor())->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->last();
        $sender = $last ? NodeTitle::email($last) : mb_strtolower(trim((string) $candidate?->value('sender')));
        if ($sender === '' || ! str_contains($sender, '@') || in_array($sender, [...Message::ownEmails(), ...Message::ownMailboxes()], true)) {
            return null;
        }

        return [
            'url' => "{$base}/{$thread->id}/vendor",
            'sender' => $sender,
            'name' => (string) ($candidate?->value('vendor') ?: self::guessName($last?->text_body ?? '')),
            'domain' => (string) BindSender::ownDomain($sender),
        ];
    }

    /**
     * Название из подписи письма: «ООО "Страховая компания "ТИТ"» → «ТИТ», «АО «СОГАЗ»» → «СОГАЗ». Последняя часть в
     * кавычках после формы собственности, без «Страховая компания». Не нашлось — пусто, человек впишет.
     */
    public static function guessName(string $text): string
    {
        foreach (array_reverse(preg_split('/\R/u', $text) ?: []) as $line) {
            $line = trim((string) preg_replace('/[*_]/u', '', $line));
            if (! preg_match('/(?:^|\s)(?:ООО|АО|ПАО|САО|СПАО|ОСАО|ОАО|ЗАО|НКО)\s+(.+)$/u', $line, $m)) {
                continue;
            }
            preg_match_all('/[«"„“]\s*([^«»"„“”]+?)\s*(?=[»"“”]|$)/u', $m[1], $parts);
            $name = trim((string) preg_replace('/^(?:страхов\w+\s+компани\w+|страхов\w+\s+обществ\w+|ск)\s*/iu', '', (string) last(array_filter($parts[1] ?? []))));
            if ($name !== '' && mb_strlen($name) <= 60) {
                return $name;
            }
        }

        return '';
    }
}
