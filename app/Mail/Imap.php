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
 * папки со STATUS, UID по диапазону, структура писем и их части по секциям,
 * флаги, APPEND, IDLE. Целиком письмо не качается никогда.
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

    /**
     * Структура писем без скачивания: дерево MIME, флаги, дата, размер.
     *
     * @return array<int, array{structure: array, flags: list<string>, internal_at: ?DateTimeImmutable, size: int}>
     */
    public function structures(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->select($path, false);
        // Ответ читаем сырыми строками и разбираем сами: штатный лексер webklex ломается на «"utf-8")».
        $response = $this->connection()->requestAndResponse('UID FETCH', [implode(',', array_values($uids)), '(BODYSTRUCTURE FLAGS INTERNALDATE RFC822.SIZE)'], true);
        $rows = [];
        foreach (Structure::fromResponse($response->getResponse()) as $uid => $pairs) {
            $rows[$uid] = [
                'structure' => is_array($pairs['BODYSTRUCTURE'] ?? null) ? $pairs['BODYSTRUCTURE'] : [],
                'flags' => array_values(array_filter(array_map(fn ($f) => is_scalar($f) ? (string) $f : '', (array) ($pairs['FLAGS'] ?? [])))),
                'internal_at' => $this->date($pairs['INTERNALDATE'] ?? null),
                'size' => (int) ($pairs['RFC822.SIZE'] ?? 0),
            ];
        }

        return $rows;
    }

    /** Блок заголовков одного письма. */
    public function header(string $path, int $uid): string
    {
        return $this->parts($path, $uid, ['HEADER'])['HEADER'] ?? '';
    }

    /**
     * Части письма по секциям (1, 1.2, HEADER, 2.MIME) как они лежат в письме — ещё в транспортной кодировке.
     *
     * @return array<string, string> секция → содержимое
     */
    public function parts(string $path, int $uid, array $sections): array
    {
        if (! $sections) {
            return [];
        }
        $this->select($path, false);
        $items = array_map(fn ($s) => "BODY.PEEK[$s]", $sections);
        $items[] = 'UID';
        $row = $this->connection()->fetch($items, [$uid], null, ImapConst::ST_UID)->validatedData();
        $row = (array) ($row[$uid] ?? []);
        $parts = [];
        foreach ($sections as $section) {
            $value = $row["BODY[$section]"] ?? null;
            if (is_string($value)) {
                $parts[$section] = $value;
            }
        }

        return $parts;
    }

    public function part(string $path, int $uid, string $section): ?string
    {
        return $this->parts($path, $uid, [$section])[$section] ?? null;
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
