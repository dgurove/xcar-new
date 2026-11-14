<?php

namespace App\Vendors\Actions;

use App\Mail\Account;
use App\Mail\Composer;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\SendState;
use App\Offers\Actions\SendClaimRequest;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Номера «К запросу» вендора письмом (с 14.11.2026 — самому вендору «Согласование реализации», см. `mail`; 06.11.2026, Андрей: «2 раза в день автоматически список номеров убытка
 * по Т-Страхованию»; владелец: помечать отправленными, пусто — не слать). То же, что кнопка «Отправить запрос»
 * (`SendClaimRequest`: `ClaimRequest`, пометки, «История»), только номера — письмом на почту из профиля, а не в Telegram.
 * Письмо обязано дойти: повторы в очереди, не ушло — номера ему в Telegram (`undelivered`).
 */
final class MailClaimDigest
{
    /** Сколько после слота ещё можно отправить: выкладка или упавший планировщик в 9:00 не теряют письмо. */
    public const LATE_HOURS = 3;

    /** Сколько раз пробовать отправить письмо запроса (`retry`). */
    public const ATTEMPTS = 3;

    public function __construct(private SendClaimRequest $send, private Composer $composer) {}

    /** Слот, который сейчас пора отправить (последний наступивший, моложе LATE_HOURS), или null. */
    public static function dueSlot(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now('Europe/Moscow');
        $due = null;
        foreach ([$now->subDay(), $now] as $day) {
            foreach ((array) config('xcar.claim_digest.at') as $at) {
                $slot = CarbonImmutable::parse($day->toDateString().' '.$at, 'Europe/Moscow');
                if ($slot <= $now && $now->diffInMinutes($slot, true) < self::LATE_HOURS * 60 && (! $due || $slot > $due)) {
                    $due = $slot;
                }
            }
        }

        return $due;
    }

    /** Проход часов: по каждому вендору — один раз на слот. @return int сколько писем поставлено */
    public function tick(?CarbonImmutable $now = null): int
    {
        $slot = self::dueSlot($now);
        $user = User::find(config('xcar.claim_digest.user'));
        if (! $slot || ! $user) {
            return 0;
        }
        $sent = 0;
        foreach (Vendor::whereIn('id', (array) config('xcar.claim_digest.vendors'))->get() as $vendor) {
            if (Cache::add('claim-digest:'.$vendor->id.':'.$slot->format('Y-m-d H:i'), true, now()->addDays(2))) {
                $sent += (int) (bool) $this->send($vendor, $user, $slot);
            }
        }

        return $sent;
    }

    /** Номера вендора одному человеку: запрос, пометки, письмо. Пусто — ничего. */
    public function send(Vendor $vendor, User $to, CarbonImmutable $slot): ?ClaimRequest
    {
        $ids = ClaimRequest::pendingFor($vendor)->pluck('offers.id')->all();
        $request = $ids ? ($this->send)($vendor, $ids, $to, notify: false) : null;
        $request && $this->mail($request, $to);

        return $request;
    }

    /** Кому номера письмом (`xcar.claim_digest.user`) — и по расписанию, и по кнопке «Отправить запрос». */
    public static function recipient(): ?User
    {
        return User::find(config('xcar.claim_digest.user'));
    }

    /** Номера этого вендора уходят письмом (`xcar.claim_digest.vendors`). */
    public static function covers(Vendor $vendor): bool
    {
        return in_array($vendor->id, array_map('intval', (array) config('xcar.claim_digest.vendors')), true);
    }

    /**
     * Готовый запрос — письмом вендору «Согласование реализации» (14.11.2026, владелец: Андрею письмо приходило, а он его
     * Т-Страхованию не пересылал — «хочет, чтобы система их отправила»): с ящика CRM (`claim_digest.account`, deal@), кому и
     * скрытая копия — из конфига, простым текстом, подпись — имя сотрудника `claim_digest.user`. Письмо встаёт в почту CRM,
     * ответ страховой приходит в его ветку. Несколько запросов — одним письмом (догнать неотправленное, `claims:mail-requests`).
     * Ушло — владельцу в Telegram (`sent`), не ушло — повтор часами (`retry`), после третьей попытки — номера в Telegram.
     *
     * @param  ClaimRequest|list<ClaimRequest>  $requests
     */
    public function mail(ClaimRequest|array $requests, ?User $signer = null): bool
    {
        $requests = is_array($requests) ? $requests : [$requests];
        $signer ??= self::recipient();
        $account = Account::sending()->where('email', config('xcar.claim_digest.account'))->first();
        $to = array_values(array_filter((array) config('xcar.claim_digest.to')));
        if (! $account || ! $to) {
            Log::warning('Номера «К запросу»: нет ящика или адресов вендора, номера — в Telegram', ['requests' => array_map(fn ($r) => $r->id, $requests)]);
            foreach ($requests as $r) {
                $this->undelivered($r->id);
            }

            return false;
        }
        $refs = array_values(array_unique(array_merge(...array_map(fn (ClaimRequest $r) => (array) $r->refs, $requests))));
        $message = DB::transaction(function () use ($account, $to, $refs, $requests, $signer) {
            $message = $this->composer->create($account, [
                'to' => implode(', ', $to), 'bcc' => implode(', ', (array) config('xcar.claim_digest.bcc')),
                'subject' => (string) config('xcar.claim_digest.subject', 'Согласование реализации'),
                'body' => self::body($refs, $signer?->name),
            ], null, $signer, dispatch: false);
            // Текстовая часть — те же строки как есть: разбор HTML терял пустые строки между абзацами или удваивал их.
            $message->update(['text_body' => self::text($refs, $signer?->name)]);
            $message->thread?->update(['vendor_id' => $requests[0]->vendor_id]);
            ClaimRequest::whereIn('id', array_map(fn ($r) => $r->id, $requests))->update(['message_id' => $message->id]);

            return $message;
        });
        $this->composer->dispatch($message);

        return true;
    }

    /** Текст письма — как писал владелец Т-Страхованию сам: номера столбиком, без вёрстки. @return list<string> */
    public static function lines(array $refs, ?string $signer): array
    {
        $lines = ['Добрый день!', '', 'Просим согласовать возможность реализации по убыткам:', '', ...$refs, '',
            'Оперативную оплату и вывоз гарантируем после проверки VIN.', '', 'С уважением,'];
        $signer && $lines[] = $signer;

        return $lines;
    }

    public static function body(array $refs, ?string $signer): string
    {
        return implode("<br>\n", array_map(fn ($l) => e($l), self::lines($refs, $signer)));
    }

    public static function text(array $refs, ?string $signer): string
    {
        return implode("\n", self::lines($refs, $signer));
    }

    /** Письмо запроса ушло (`MessageSent`) — владельцу в Telegram. */
    public function sent(MessageSent $e): void
    {
        foreach (ClaimRequest::where('message_id', $e->message->id)->get() as $request) {
            $this->delivered($request->id);
        }
    }

    /**
     * Шаг часов: письмо запроса не ушло — снова в очередь, до трёх попыток (`SendMessage` пробует один раз); после третьей —
     * номера получателю в Telegram и владельцу «не ушло». @return int сколько поставлено снова
     */
    public function retry(): int
    {
        $n = 0;
        $failed = Message::where('send_state', SendState::Failed->value)->whereIn('id', ClaimRequest::whereNotNull('message_id')->select('message_id'))
            ->where('updated_at', '>', now()->subDays(2))->get();
        foreach ($failed as $message) {
            if ($message->send_attempts < self::ATTEMPTS) {
                if (Message::whereKey($message->id)->where('send_state', SendState::Failed->value)->update(['send_state' => SendState::Queued->value, 'updated_at' => now()])) {
                    $this->composer->dispatch($message);
                    $n++;
                }
            } elseif (Cache::add('claim-digest:undelivered:'.$message->id, true, now()->addDays(3))) {
                foreach (ClaimRequest::where('message_id', $message->id)->get() as $request) {
                    $this->undelivered($request->id);
                }
            }
        }

        return $n;
    }

    /** Письмо ушло — владельцу в Telegram (06.11.2026: «чтобы я знал, что всё ок»), и по расписанию, и по кнопке. */
    public function delivered(int $requestId): void
    {
        $request = ClaimRequest::with('vendor')->find($requestId);
        if ($request) {
            // Сами номера, а не «7 номеров» (06.11.2026, владелец) — `<code>`, копируются касанием.
            $this->tellOwner('<b>'.e($request->vendor->name).': письмо «'.e((string) config('xcar.claim_digest.subject')).'» ушло</b>'."\n".self::count($request).' — '.e(implode(', ', (array) config('xcar.claim_digest.to')))
                ."\n\n".implode("\n", array_map(fn ($r) => '<code>'.e($r).'</code>', $request->refs)));
        }
    }

    /** Письмо не дошло — номера получателю в Telegram (они уже помечены отправленными), владельцу — что письма не было. */
    public function undelivered(int $requestId, ?int $toId = null): void
    {
        $request = ClaimRequest::with(['vendor', 'user'])->find($requestId);
        $to = User::find($toId ?? config('xcar.claim_digest.user'));
        if (! $request || ! $to) {
            return;
        }
        $tg = $this->send->telegram($request, $to);
        $tg || Log::error('Номера «К запросу» не дошли ни письмом, ни в Telegram', ['request' => $requestId, 'user' => $to->id]);
        $this->tellOwner('<b>'.e($request->vendor->name).': письмо с номерами не ушло</b>'."\n".e($request->vendor->name).', '.self::count($request).($tg ? ' — отправили ему в Telegram' : ' — и в Telegram не дошло')
            ."\n\n".implode("\n", array_map(fn ($r) => '<code>'.e($r).'</code>', $request->refs)));
    }

    private static function count(ClaimRequest $request): string
    {
        $n = count($request->refs);

        return $n.' '.\App\Support\Plural::of($n, ['номер', 'номера', 'номеров']);
    }

    private function tellOwner(string $html): void
    {
        foreach (User::withRole(\App\Users\Role::Owner)->whereNotNull('telegram_chat_id')->get() as $owner) {
            try {
                app(\App\Telegram\Bot::class)->send((int) $owner->telegram_chat_id, $html);
            } catch (\Throwable $e) {
                Log::warning('Номера «К запросу»: владельцу в Telegram не ушло: '.$e->getMessage());
            }
        }
    }
}
