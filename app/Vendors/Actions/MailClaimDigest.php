<?php

namespace App\Vendors\Actions;

use App\Mail\SystemMail;
use App\Offers\Actions\SendClaimRequest;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Номера «К запросу» вендора письмом сотруднику (06.11.2026, Андрей: «2 раза в день автоматически список номеров убытка
 * по Т-Страхованию»; владелец: помечать отправленными, пусто — не слать). То же, что кнопка «Отправить запрос»
 * (`SendClaimRequest`: `ClaimRequest`, пометки, «История»), только номера — письмом на почту из профиля, а не в Telegram.
 * Письмо обязано дойти: повторы в очереди, не ушло — номера ему в Telegram (`undelivered`).
 */
final class MailClaimDigest
{
    /** Сколько после слота ещё можно отправить: выкладка или упавший планировщик в 9:00 не теряют письмо. */
    public const LATE_HOURS = 3;

    public function __construct(private SendClaimRequest $send) {}

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
        $request && $this->mail($request, $to, $slot);

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
     * Готовый запрос — письмом получателю (06.11.2026, владелец: «нажал „Отправить“ в 12 — пусть и письмо Андрею, в 18 —
     * только новые»): номера уже помечены, следующее письмо по расписанию их не повторит. Ушло — владельцу в Telegram.
     */
    public function mail(ClaimRequest $request, ?User $to = null, ?CarbonImmutable $at = null): bool
    {
        $to ??= self::recipient();
        $request->loadMissing('vendor');
        if (! $to?->email) {
            Log::warning('Номера «К запросу»: у получателя нет почты, номера — в Telegram', ['user' => $to?->id, 'request' => $request->id]);
            $to && $this->undelivered($request->id, $to->id);

            return false;
        }
        SystemMail::queue(self::email($request, $to, $at ?? CarbonImmutable::now('Europe/Moscow')), ['kind' => 'claim-digest', 'request' => $request->id, 'to' => $to->id]);

        return true;
    }

    public static function email(ClaimRequest $request, User $to, CarbonImmutable $slot): \Symfony\Component\Mime\Email
    {
        $n = count($request->refs);
        $count = $n.' '.\App\Support\Plural::of($n, ['номер', 'номера', 'номеров']);
        $html = view('emails.claim-digest', [
            'title' => $request->vendor->name, 'subtitle' => $count.', '.$slot->translatedFormat('j F, G:i'),
            'preheader' => implode(', ', array_slice($request->refs, 0, 3)).($n > 3 ? ' и ещё '.($n - 3) : ''), 'refs' => $request->refs,
        ])->render();

        return SystemMail::make($to->email, $to->name, $request->vendor->name.': '.$count.' к запросу', $html, ['text' => $request->text()]);
    }

    /** Письмо ушло — владельцу в Telegram (06.11.2026: «чтобы я знал, что всё ок»), и по расписанию, и по кнопке. */
    public function delivered(int $requestId, int $toId): void
    {
        $request = ClaimRequest::with('vendor')->find($requestId);
        $to = User::find($toId);
        if ($request && $to) {
            // Сами номера, а не «7 номеров» (06.11.2026, владелец) — `<code>`, копируются касанием.
            $this->tellOwner('<b>'.e($to->name).': номера на почте</b>'."\n".e($request->vendor->name).', '.self::count($request).' — '.e((string) $to->email)
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
        $this->tellOwner('<b>'.e($to->name).': письмо с номерами не ушло</b>'."\n".e($request->vendor->name).', '.self::count($request).($tg ? ' — отправили ему в Telegram' : ' — и в Telegram не дошло')
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
