<?php

namespace App\WhatsApp;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Share\Caption;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Telegram\Offers\Card;
use App\Telegram\Offers\Subscriber;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * Кому: в группе люди, и каждый должен видеть машину сам — уходит только то, что уже открыто каждому участнику
 * (`Offer::visibleTo`: волны, «Открыты только Вам», «Не показывать»). Участник — по номеру среди `users`; номер не из xcar
 * или человек, которому xcar закрыт, — отправка стоит, `whatsapp:status` поднимает тревогу (`deploy/check.sh`). Сам
 * отправитель (`me`) не считается.
 *
 * Что: открытое с `whatsapp.since` (пусто — само ничего не уходит, только `whatsapp:test`), не демо, по одному разу на
 * машину навсегда (`whatsapp_sent`; снятое не трогаем — владелец, вернувшееся второй раз не шлём).
 *
 * Как — чтобы номер не сочли рассыльщиком (обсуждали 30.10–03.11.2026): по одному, пауза 30–90 с, после 15–20 подряд —
 * перерыв 5–10 минут; ночью (22:00–08:00 по Москве) тишина, ночная пачка уходит с утра; не больше `whatsapp.daily` в сутки.
 * Дубль хуже потери: ключ сообщения — машина, служба помнит ключи и повтор с тем же ключом второй раз не отправляет.
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

    /** Превью документа: длинная сторона. */
    private const THUMB = 240;

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
        if (! $this->candidates($since)->exists()) {
            return false;
        }
        $status = $this->gateway->status();
        if (self::trouble($status) !== null) {
            return false;
        }
        [$people, $outsiders] = $this->members($status);
        if ($outsiders) {
            return false;
        }
        $offer = $this->candidates($since, $people)->first();

        return $offer !== null && $this->deliver($offer, 'offer:'.$offer->id, true);
    }

    /**
     * Отправить машину в группу с паузой после. record — запомнить в `whatsapp_sent` (проверочная отправка не пишет).
     */
    public function deliver(Offer $offer, string $key, bool $record): bool
    {
        if ($record) {
            DB::table('whatsapp_sent')->insertOrIgnore(['offer_id' => $offer->id, 'created_at' => now()]);
        }
        try {
            [$pdf, $thumb] = $this->files($offer);
            $id = $this->gateway->send($key, self::caption($offer), $pdf, $thumb);
        } catch (Throwable $e) {
            // Служба отказала по самому сообщению — считаем попытку; лежит связь или служба остановлена — машина ждёт.
            if ($record && $e instanceof RequestException && in_array($e->response->status(), [400, 422, 502], true)) {
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
    }

    /**
     * Что не так со службой и группой — для тревоги (`whatsapp:status`); пусто — всё в порядке.
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
        if ($trouble = self::trouble($status)) {
            return [$trouble];
        }
        [, $outsiders] = $this->members($status);

        return $outsiders ? ['в группе «'.$status['group'].'» не из xcar или с закрытым доступом: '.implode(', ', $outsiders)] : [];
    }

    /** Служба не может отправить — почему; null — может. */
    public static function trouble(array $status): ?string
    {
        return match (true) {
            (bool) $status['stopped'] => 'служба остановлена: '.$status['stopped'],
            ! $status['logged_in'] => 'устройство не привязано к WhatsApp',
            ! $status['connected'] => 'нет связи с WhatsApp',
            ! $status['in_group'] => 'номер не состоит в группе',
            default => null,
        };
    }

    /**
     * Участники группы: люди xcar, которым она служит, и все остальные (номер или JID).
     *
     * @return array{0: Collection<int, User>, 1: list<string>}
     */
    public function members(array $status): array
    {
        $phones = array_values(array_diff($status['members'], [$status['me']]));
        $users = User::query()->whereIn(DB::raw("regexp_replace(coalesce(phone, ''), '\\D', '', 'g')"), $phones)->get()
            ->keyBy(fn (User $u) => preg_replace('/\D/', '', (string) $u->phone));
        $people = collect();
        $outsiders = [];
        foreach ($phones as $phone) {
            $user = $users[$phone] ?? null;
            if (Subscriber::eligible($user)) {
                $people->push($user);
            } else {
                $outsiders[] = ctype_digit($phone) ? '+'.$phone.($user ? ' ('.$user->name.')' : '') : $phone;
            }
        }

        return [$people, $outsiders];
    }

    /** Подпись: текст для клиента и ссылка на машину в XCar. */
    public static function caption(Offer $offer): string
    {
        return implode("\n\n", array_filter([Caption::forward($offer), Caption::link($offer)]));
    }

    /**
     * Очередь: в продаже, приём идёт, не демо, шеринг открыт, вышло с `since`, ещё не уходило; с людьми — только открытое
     * каждому из них.
     *
     * @param  ?Collection<int, User>  $people
     */
    public function candidates(Carbon $since, ?Collection $people = null): Builder
    {
        $q = Offer::query()->where('offers.state', OfferState::Open)->where('offers.is_demo', false)
            ->where('offers.share_locked', false)->where('offers.share_locked_by_estimate', false)
            ->where(fn ($w) => $w->whereNull('offers.bids_close_at')->orWhere('offers.bids_close_at', '>', now()))
            ->where('offers.published_at', '>=', $since)
            ->whereNotExists(fn ($x) => $x->from('whatsapp_sent as w')->whereColumn('w.offer_id', 'offers.id')
                ->where(fn ($y) => $y->whereNotNull('w.message_id')->orWhere('w.attempts', '>=', self::ATTEMPTS)))
            ->orderBy('offers.published_at')->orderBy('offers.id');
        foreach ($people ?? [] as $user) {
            $q->whereIn('offers.id', Offer::visibleTo($user)->select('offers.id'));
        }

        return $q;
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
        $thumb = null;
        if ($photo = $offer->mainPhoto()) {
            [$bytes, $name] = Card::jpeg($photo, self::THUMB);
            $thumb = str_ends_with($name, '.jpg') ? $bytes : null;
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
