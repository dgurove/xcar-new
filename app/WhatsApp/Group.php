<?php

namespace App\WhatsApp;

use App\Media\Jpeg;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Share\Caption;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Предложения в группу WhatsApp с личного номера владельца (03.11.2026, владелец: «те же пдфы … через вацап из цюриха в
 * групповой чат с моего аккаунта»). Отправляет служба в Цюрихе (`Gateway`), решает всё здесь. Сообщение — как карточка
 * бота предложений: PDF (`SharePdf::prepared`) с подписью для клиента (`Caption::forward`) и ссылкой на XCar последней
 * строкой; «срочно» и срок приёма не пишем (владелец). Кадров нет — текстом. Закрытый шеринг в WhatsApp не уходит: защиты от
 * пересылки там нет.
 *
 * Кому — решает владелец, назвав группу (`TARGET` службы): состав группы не проверяем, волн и «только вам» здесь нет
 * (владелец 03.11.2026: «если прошу в какую-то группу присылать — значит надо присылать»).
 *
 * Что: открытое с `whatsapp.since` (пусто — само ничего не уходит, только `whatsapp:test`), не демо, по одному разу на
 * машину навсегда (`whatsapp_sent`; снятое не трогаем — владелец, вернувшееся второй раз не шлём).
 *
 * Как — чтобы номер не сочли рассыльщиком (обсуждали 30.10–03.11.2026): по одному, пауза 30–90 с, после 15–20 подряд —
 * перерыв 5–10 минут; ночью (22:00–08:00 по Москве) тишина, ночная пачка уходит с утра; не больше `whatsapp.daily` в сутки.
 * Дубль хуже потери: ключ сообщения — машина, служба помнит ключи и повтор с тем же ключом второй раз не отправляет.
 * Отправка — под общим замком с паузой внутри: `whatsapp:test` и обход не уходят вплотную друг к другу.
 */
final class Group
{
    private const PAUSE = [30, 90];

    private const STREAK = [15, 20];

    private const REST = [300, 600];

    /** После такой тишины серия начинается заново. */
    private const STREAK_GAP = 600;

    /** Тихие часы по Москве: с 22 до 8 (утром — ещё до 20 минут, разных каждый день). */
    private const QUIET = [22, 8];

    /** Столько отказов службы на одну машину — и её больше не пробуем. */
    private const ATTEMPTS = 3;

    /** Превью документа: длинная сторона и потолок — оно едет внутри самого сообщения. */
    private const THUMB = 240;

    private const THUMB_BYTES = 60_000;

    private const PACE = 'whatsapp:pace';

    public function __construct(private Gateway $gateway) {}

    public function since(): ?Carbon
    {
        $since = trim((string) config('xcar.whatsapp.since'));

        return $since === '' ? null : Carbon::parse($since);
    }

    /** Сколько секунд до следующего сообщения: пауза между ними. */
    public function wait(): int
    {
        return max(0, (int) (Cache::get(self::PACE)['next'] ?? 0) - now()->getTimestamp());
    }

    /** Отправить одну машину, если пора. true — ушла. */
    public function step(): bool
    {
        $since = $this->since();
        if (! $this->gateway->configured() || ! $since || $this->quiet(now()) || $this->wait() > 0 || $this->sentToday() >= (int) config('xcar.whatsapp.daily')) {
            return false;
        }
        // Нечего слать — к Цюриху не ходим.
        $offer = $this->candidates($since)->first();
        if ($offer === null || self::trouble($this->gateway->status()) !== null) {
            return false;
        }

        return $this->deliver($offer, 'offer:'.$offer->id, true);
    }

    /**
     * Отправить машину в группу: под замком, выждав паузу, и с паузой после. record — запомнить в `whatsapp_sent`
     * (проверочная отправка не пишет).
     */
    public function deliver(Offer $offer, string $key, bool $record): bool
    {
        return Cache::lock('whatsapp:send', 300)->block(200, function () use ($offer, $key, $record) {
            if ($wait = $this->wait()) {
                sleep($wait);
            }
            if ($record) {
                DB::table('whatsapp_sent')->insertOrIgnore(['offer_id' => $offer->id, 'created_at' => now()]);
            }
            try {
                [$pdf, $thumb] = $this->files($offer);
                $id = $this->gateway->send($key, self::caption($offer), $pdf, $thumb);
            } catch (Throwable $e) {
                // Не дошло до службы, она остановлена, не в группе или ключ не тот — машина ждёт, попытка не тратится.
                // Остальное (PDF не собрался, служба отказала по самому сообщению) — попытка: иначе одна машина навсегда
                // держала бы всю очередь.
                $service = $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [401, 409, 503], true));
                if ($record && ! $service) {
                    DB::table('whatsapp_sent')->where('offer_id', $offer->id)->increment('attempts');
                }
                Log::warning('WhatsApp: предложение не ушло', ['offer' => $offer->id, 'error' => $e->getMessage()]);
                Cache::forever(self::PACE, ['next' => now()->getTimestamp() + 60] + (Cache::get(self::PACE) ?? []));

                return false;
            }
            if ($record) {
                DB::table('whatsapp_sent')->where('offer_id', $offer->id)->update(['message_id' => $id, 'sent_at' => now()]);
            }
            $this->paced();

            return true;
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

    /** Служба не может отправить — почему; null — может. */
    public static function trouble(array $status): ?string
    {
        return match (true) {
            (bool) $status['stopped'] => 'служба остановлена: '.$status['stopped'],
            ! $status['logged_in'] => 'устройство не привязано к WhatsApp',
            ! $status['connected'] => 'нет связи с WhatsApp',
            ($status['in_group'] ?? null) === false => 'номер не состоит в группе',
            ($status['in_group'] ?? null) === null => 'не узнать группу у WhatsApp: '.($status['group_error'] ?? 'нет ответа'),
            default => null,
        };
    }

    /** Подпись: текст для клиента и ссылка на машину в XCar. */
    public static function caption(Offer $offer): string
    {
        return implode("\n", array_filter([Caption::forward($offer), Caption::link($offer)]));
    }

    /**
     * Очередь: в продаже, приём идёт, не демо, шеринг не закрыт (`share_locked_by_estimate` закрывает его только тому, кого
     * просили оценить, — группе он не помеха), вышло с `since`, ещё не уходило.
     */
    public function candidates(Carbon $since): Builder
    {
        return Offer::query()->where('offers.state', OfferState::Open)->where('offers.is_demo', false)
            ->where('offers.share_locked', false)
            ->where(fn ($w) => $w->whereNull('offers.bids_close_at')->orWhere('offers.bids_close_at', '>', now()))
            ->where('offers.published_at', '>=', $since)
            ->whereNotExists(fn ($x) => $x->from('whatsapp_sent as w')->whereColumn('w.offer_id', 'offers.id')
                ->where(fn ($y) => $y->whereNotNull('w.message_id')->orWhere('w.attempts', '>=', self::ATTEMPTS)))
            ->orderBy('offers.published_at')->orderBy('offers.id');
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
        return DB::table('whatsapp_sent')->where('sent_at', '>=', now()->tz('Europe/Moscow')->startOfDay())->count();
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
