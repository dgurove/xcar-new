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
        if (! $request) {
            return null;
        }
        if (! $to->email) {
            Log::warning('Номера «К запросу»: у получателя нет почты, номера — в Telegram', ['user' => $to->id, 'request' => $request->id]);
            $this->undelivered($request->id);

            return $request;
        }
        SystemMail::queue(self::email($request, $to, $slot), ['kind' => 'claim-digest', 'request' => $request->id]);

        return $request;
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

    /** Письмо не дошло — номера получателю в Telegram: они уже помечены отправленными и иначе потерялись бы. */
    public function undelivered(int $requestId): void
    {
        $request = ClaimRequest::with(['vendor', 'user'])->find($requestId);
        if ($request?->user && ! $this->send->telegram($request, $request->user)) {
            Log::error('Номера «К запросу» не дошли ни письмом, ни в Telegram', ['request' => $requestId, 'user' => $request->user_id]);
        }
    }
}
