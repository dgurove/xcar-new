<?php

namespace App\WhatsApp;

use App\Media\Jpeg;
use App\Offers\Offer;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp с личного номера владельца (03.11.2026): группа и личные. Отправляет служба в Цюрихе (`Gateway`), что слать —
 * `Queue`, как выглядит — `Message`; здесь — когда и в каком темпе. Сообщение с машиной — как карточка бота предложений:
 * PDF (`SharePdf::prepared`) с подписью для клиента и ссылкой; «срочно» и срок приёма не пишем (владелец).
 *
 * Пока `whatsapp.since` пуст, само не уходит ничего (только `whatsapp:test`) и менеджерам WhatsApp не предлагается (`open`).
 *
 * Как — чтобы номер не сочли рассыльщиком (обсуждали 30.10–03.11.2026): по одному, пауза 30–90 с, после 15–20 подряд —
 * перерыв 5–10 минут; ночью (22:00–08:00 по Москве) тишина, ночное уходит с утра; не больше `whatsapp.daily` в сутки — на все
 * виды вместе. Дубль хуже потери: служба помнит ключи, повтор с тем же ключом второй раз не отправляет. Отправка — под общим
 * замком с паузой внутри: `whatsapp:test` и обход не уходят вплотную друг к другу. Ответ на привязку — сразу (`reply`).
 */
final class Sender
{
    private const PAUSE = [30, 90];

    private const STREAK = [15, 20];

    private const REST = [300, 600];

    /** После такой тишины серия начинается заново. */
    private const STREAK_GAP = 600;

    /** Тихие часы по Москве: с 22 до 8 (утром — ещё до 20 минут, разных каждый день). */
    private const QUIET = [22, 8];

    /** Превью документа: длинная сторона и потолок — оно едет внутри самого сообщения. */
    private const THUMB = 240;

    private const THUMB_BYTES = 60_000;

    private const PACE = 'whatsapp:pace';

    public function __construct(private Gateway $gateway, private Queue $queue) {}

    public function since(): ?Carbon
    {
        $since = trim((string) config('xcar.whatsapp.since'));

        return $since === '' ? null : Carbon::parse($since);
    }

    /** WhatsApp открыт менеджерам: служба есть и рассылка включена — тогда его и предлагают подключить. */
    public static function open(): bool
    {
        return trim((string) config('xcar.whatsapp.via')) !== '' && trim((string) config('xcar.whatsapp.since')) !== '';
    }

    /** Сколько секунд до следующего сообщения: пауза между ними. */
    public function wait(): int
    {
        return max(0, (int) (Cache::get(self::PACE)['next'] ?? 0) - now()->getTimestamp());
    }

    /** Отправить одно сообщение, если пора. true — ушло. */
    public function step(): bool
    {
        $since = $this->since();
        if (! $this->gateway->configured() || ! $since || $this->quiet(now()) || $this->wait() > 0 || $this->sentToday() >= (int) config('xcar.whatsapp.daily')) {
            return false;
        }
        // Нечего слать — к Цюриху не ходим.
        $message = $this->queue->next($since);
        if ($message === null || self::trouble($this->gateway->status(), $message->kind === Message::GROUP) !== null) {
            return false;
        }

        return $this->deliver($message, true);
    }

    /**
     * Отправить: под замком, выждав паузу, и с паузой после. record — запомнить в `whatsapp_messages` (проверочная отправка
     * не пишет).
     */
    public function deliver(Message $message, bool $record): bool
    {
        return Cache::lock('whatsapp:send', 300)->block(200, function () use ($message, $record) {
            if ($wait = $this->wait()) {
                sleep($wait);
            }
            $row = DB::table('whatsapp_messages')->where('key', $message->key);
            if ($record) {
                DB::table('whatsapp_messages')->insertOrIgnore(['key' => $message->key, 'kind' => $message->kind,
                    'offer_id' => $message->offerId, 'user_id' => $message->userId, 'estimate_id' => $message->estimateId, 'created_at' => now()]);
            }
            try {
                [$pdf, $thumb] = $message->offer ? $this->files($message->offer) : [null, null];
                $id = $this->gateway->send($message->key, $message->text, $pdf, $thumb, $message->to);
            } catch (Throwable $e) {
                // Не дошло до службы, она остановлена, не в группе или ключ не тот — сообщение ждёт, попытка не тратится.
                // Остальное (PDF не собрался, служба отказала по самому сообщению, человек не писал первым) — попытка:
                // иначе одно сообщение навсегда держало бы всю очередь.
                $service = $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [401, 409, 503], true));
                if ($record && ! $service) {
                    (clone $row)->increment('attempts');
                }
                Log::warning('WhatsApp: сообщение не ушло', ['key' => $message->key, 'error' => $e->getMessage()]);
                Cache::forever(self::PACE, ['next' => now()->getTimestamp() + 60] + (Cache::get(self::PACE) ?? []));

                return false;
            }
            if ($record) {
                (clone $row)->update(['message_id' => $id, 'sent_at' => now()]);
            }
            $this->paced();

            return true;
        });
    }

    /**
     * Ответ человеку на его сообщение (привязка): сразу, мимо паузы, тихих часов и потолка — так отвечает человек. Не дошло —
     * не страшно: привязка уже стоит, на сайте видно «Готово».
     */
    public function reply(string $to, string $text, string $key): void
    {
        try {
            $this->gateway->send($key, $text, null, null, $to);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: ответ не ушёл', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Группа: название и ссылка-приглашение — для шторки на сайте и ответа на привязку. У службы спрашиваем раз в 10 минут;
     * не ответила — прежнее.
     *
     * @return array{name: ?string, invite: ?string}
     */
    public function group(): array
    {
        $known = Cache::get('whatsapp:group-known') ?? ['name' => null, 'invite' => null];
        if (! $this->gateway->configured()) {
            return $known;
        }

        return Cache::remember('whatsapp:group', 600, function () use ($known) {
            try {
                $status = $this->gateway->status();
                $known = ['name' => $status['group'] ?? $known['name'], 'invite' => $status['invite'] ?? $known['invite']];
                Cache::forever('whatsapp:group-known', $known);
            } catch (Throwable) {
            }

            return $known;
        });
    }

    /**
     * Что не так со службой — для тревоги (`whatsapp:status`); пусто — всё в порядке.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (! $this->gateway->configured()) {
            return [];
        }
        try {
            $status = $this->gateway->status();
        } catch (Throwable $e) {
            return ['служба в Цюрихе не отвечает: '.$e->getMessage()];
        }
        $trouble = self::trouble($status);

        return $trouble === null ? [] : [$trouble];
    }

    /** Служба не может отправить — почему; null — может. group — нужна ли группа (личным — нет). */
    public static function trouble(array $status, bool $group = true): ?string
    {
        return match (true) {
            (bool) $status['stopped'] => 'служба остановлена: '.$status['stopped'],
            ! $status['logged_in'] => 'устройство не привязано к WhatsApp',
            ! $status['connected'] => 'нет связи с WhatsApp',
            $group && ($status['in_group'] ?? null) === false => 'номер не состоит в группе',
            $group && ($status['in_group'] ?? null) === null => 'не узнать группу у WhatsApp: '.($status['group_error'] ?? 'нет ответа'),
            default => null,
        };
    }

    /** @return array{0: ?array{0: string, 1: string}, 1: ?string} PDF (содержимое, имя) и превью JPEG */
    private function files(Offer $offer): array
    {
        $subject = Subject::offer($offer);
        $share = app(SharePdf::class);
        if ($share->preparedKey($subject) === null) {
            return [null, null];
        }
        $pdf = [(string) file_get_contents($share->prepared($subject)), $subject->fileName()];
        $photo = $offer->mainPhoto();
        $thumb = $photo ? Jpeg::fit($photo, self::THUMB, 70) : null;
        if ($thumb !== null && strlen($thumb) > self::THUMB_BYTES) {
            $thumb = null;
        }

        return [$pdf, $thumb];
    }

    private function quiet(Carbon $now): bool
    {
        $msk = $now->copy()->tz('Europe/Moscow');
        // Утро — не ровно в 8:00: робот выходит минута в минуту.
        $morning = self::QUIET[1] * 60 + crc32($msk->toDateString()) % 20;

        return $msk->hour >= self::QUIET[0] || $msk->hour * 60 + $msk->minute < $morning;
    }

    private function sentToday(): int
    {
        return DB::table('whatsapp_messages')->where('sent_at', '>=', now()->tz('Europe/Moscow')->startOfDay())->count();
    }

    /** После сообщения: пауза, а каждые 15–20 подряд — перерыв. */
    private function paced(): void
    {
        $pace = Cache::get(self::PACE) ?? [];
        $now = now()->getTimestamp();
        $streak = $now - ($pace['last'] ?? 0) > self::STREAK_GAP ? 1 : ($pace['streak'] ?? 0) + 1;
        $limit = $pace['limit'] ?? random_int(...self::STREAK);
        if ($streak >= $limit) {
            [$pause, $streak, $limit] = [random_int(...self::REST), 0, random_int(...self::STREAK)];
        } else {
            $pause = random_int(...self::PAUSE);
        }
        Cache::forever(self::PACE, ['next' => $now + $pause, 'last' => $now, 'streak' => $streak, 'limit' => $limit]);
    }
}
