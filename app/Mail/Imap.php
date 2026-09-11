<?php

namespace App\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP as ImapConst;

/**
 * Разговор с IMAP-сервером по протоколу, без объектной надстройки webklex:
 * папки со STATUS, UID по диапазону, сырые письма пачками, флаги, APPEND, IDLE.
 * Проверено на mail.ru: есть IDLE, UIDPLUS, LIST-STATUS; нет CONDSTORE.
 */
final class Imap
{
    private ?Client $client = null;

    private ?string $selected = null;

    private ?bool $selectedWritable = null;

    public function __construct(private Account $account, private int $timeout = 30) {}

    /** @return list<array{path: string, name: string, delimiter: ?string, kind: FolderKind, uid_validity: ?int, uid_next: ?int, messages: ?int, unseen: ?int}> */
    public function folders(): array
    {
        $list = $this->connection()->folders('', '*')->validatedData();
        $folders = [];
        foreach ($list as $path => $meta) {
            $status = $this->status((string) $path, (array) ($meta['flags'] ?? []), (string) ($meta['delimiter'] ?? '/'));
            if ($status) {
                $folders[] = $status;
            }
        }

        return $folders;
    }

    public function status(string $path, array $flags = [], ?string $delimiter = null): ?array
    {
        try {
            $status = $this->connection()->folderStatus($path)->validatedData();
        } catch (Throwable) {
            return null; // \Noselect-папки STATUS не отдают
        }

        return [
            'path' => $path,
            'name' => $this->decodePath($path),
            'delimiter' => $delimiter,
            'kind' => FolderKind::fromImapAttributes($flags, $path),
            'uid_validity' => isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null,
            'uid_next' => isset($status['uidnext']) ? (int) $status['uidnext'] : null,
            'messages' => isset($status['messages']) ? (int) $status['messages'] : null,
            'unseen' => isset($status['unseen']) ? (int) $status['unseen'] : null,
        ];
    }

    /** @return list<int> */
    public function uids(string $path, int $fromUid, ?DateTimeInterface $since = null): array
    {
        $this->select($path, false);
        $criteria = ['UID', $fromUid.':*'];
        if ($since) {
            $criteria[] = 'SINCE';
            $criteria[] = $since->format('j-M-Y');
        }
        $result = $this->connection()->search($criteria, ImapConst::ST_UID)->validatedData();
        // «n:*» на сервере без писем с UID ≥ n по стандарту всё равно отдаёт последнее письмо.
        // Без строки «* SEARCH» библиотека подсовывает вместо данных ответ «OK» — берём только числа.
        $uids = array_values(array_filter(array_map('intval', array_filter((array) $result, 'is_numeric')), fn (int $uid) => $uid >= $fromUid));
        sort($uids);

        return $uids;
    }

    /** @return array<int, int> uid → байт */
    public function sizes(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->select($path, false);
        $rows = $this->connection()->fetch(['RFC822.SIZE'], array_values($uids), null, ImapConst::ST_UID)->validatedData();
        $sizes = [];
        foreach ((array) $rows as $uid => $row) {
            $sizes[(int) $uid] = (int) (is_array($row) ? ($row['RFC822.SIZE'] ?? 0) : $row);
        }

        return $sizes;
    }

    /** @return array<int, string> uid → блок заголовков */
    public function headers(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->select($path, false);
        $rows = $this->connection()->fetch(['RFC822.HEADER'], array_values($uids), null, ImapConst::ST_UID)->validatedData();
        $headers = [];
        foreach ((array) $rows as $uid => $row) {
            $headers[(int) $uid] = (string) (is_array($row) ? ($row['RFC822.HEADER'] ?? '') : $row);
        }

        return $headers;
    }

    /** @return list<array{uid: int, raw: string, flags: list<string>, internal_at: ?DateTimeImmutable, size: int}> */
    public function fetch(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->select($path, false);
        $rows = $this->connection()->fetch(['RFC822', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE'], array_values($uids), null, ImapConst::ST_UID)->validatedData();
        $messages = [];
        foreach ((array) $rows as $uid => $row) {
            $raw = (string) ($row['RFC822'] ?? '');
            if ($raw === '') {
                continue;
            }
            $messages[] = [
                'uid' => (int) $uid,
                'raw' => $raw,
                'flags' => array_map('strval', (array) ($row['FLAGS'] ?? [])),
                'internal_at' => $this->date($row['INTERNALDATE'] ?? null),
                'size' => isset($row['RFC822.SIZE']) ? (int) $row['RFC822.SIZE'] : strlen($raw),
            ];
        }

        return $messages;
    }

    /** @return array<int, list<string>> */
    public function flags(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->select($path, false);
        $rows = $this->connection()->flags(array_values($uids), ImapConst::ST_UID)->validatedData();
        $flags = [];
        foreach ((array) $rows as $uid => $row) {
            $flags[(int) $uid] = array_map('strval', (array) $row);
        }

        return $flags;
    }

    public function setFlag(string $path, int $uid, string $flag, bool $enabled): void
    {
        $this->select($path, true);
        $this->connection()->store([$flag], $uid, null, $enabled ? '+' : '-', true, ImapConst::ST_UID);
    }

    public function move(string $from, int $uid, string $to): void
    {
        $this->select($from, true);
        $this->connection()->moveMessage($to, $uid, null, ImapConst::ST_UID);
    }

    /** APPEND с UIDPLUS: возвращает присвоенный UID. Папку перед этим отпускаем, иначе сервер отдаст письмо в текущую сессию. */
    public function append(string $path, string $raw, array $flags = [], ?DateTimeInterface $date = null): ?int
    {
        $this->selected = null;
        $this->selectedWritable = null;
        $response = $this->connection()->appendMessage($path, $raw, $flags ?: null, $date?->format('d-M-Y H:i:s O'));
        $data = $response->data();
        $text = is_array($data) ? implode(' ', array_map(fn ($l) => is_scalar($l) ? (string) $l : json_encode($l), $data)) : (string) $data;

        return preg_match('/APPENDUID\s+\d+\s+(\d+)/i', $text, $m) ? (int) $m[1] : null;
    }

    /** IDLE занимает соединение целиком — под него отдельный клиент. Обновляем каждые 4 минуты: mail.ru рвёт раньше 29. */
    public function idle(string $path, callable $onNew, int $timeout): void
    {
        $client = $this->makeClient();
        $client->connect();
        $connection = $client->getConnection();
        $connection->setConnectionTimeout(20);
        $deadline = time() + $timeout;
        $renewAt = time() + 240;
        try {
            $connection->examineFolder($path);
            $connection->idle();
            while (time() < $deadline) {
                try {
                    $line = $connection->nextLine(Response::empty());
                } catch (Throwable) {
                    $line = ''; // тишина в ящике
                }
                if ($line !== '' && (str_contains($line, 'EXISTS') || str_contains($line, 'RECENT'))) {
                    $onNew();
                }
                if (time() >= $renewAt) {
                    $connection->done();
                    $connection->idle();
                    $renewAt = time() + 240;
                }
            }
            $connection->done();
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    public function disconnect(): void
    {
        if ($this->client) {
            try {
                $this->client->disconnect();
            } catch (Throwable) {
            }
        }
        $this->client = null;
        $this->selected = null;
        $this->selectedWritable = null;
    }

    private function decodePath(string $path): string
    {
        if (strtoupper($path) === 'INBOX') {
            return 'Входящие';
        }
        $decoded = @mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $path;
    }

    private function select(string $path, bool $writable): void
    {
        if ($this->selected === $path && $this->selectedWritable === $writable) {
            return;
        }
        $writable ? $this->connection()->selectFolder($path) : $this->connection()->examineFolder($path);
        $this->selected = $path;
        $this->selectedWritable = $writable;
    }

    private function connection(): ProtocolInterface
    {
        if (! $this->client) {
            $this->client = $this->makeClient();
            $this->client->connect();
        }

        return $this->client->getConnection();
    }

    private function makeClient(): Client
    {
        return (new ClientManager)->make($this->account->imapConfig() + ['timeout' => $this->timeout]);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return is_string($value) && $value !== '' ? (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone((string) config('app.timezone'))) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function fail(string $why): never
    {
        throw new RuntimeException($why);
    }
}
