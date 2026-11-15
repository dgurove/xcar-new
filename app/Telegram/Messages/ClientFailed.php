<?php

namespace App\Telegram\Messages;

use App\Mail\Direction;
use App\Mail\Message as MailMessage;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Support\Surface;
use App\Telegram\Jobs\NotifyOwner;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Человек увидел «Не получилось» (`ClientFailureController`): кто, что открывал, что ответил сервер. Только владельцу,
 * одно и то же (адрес без номеров, статус) — не чаще раза в 10 минут.
 */
final class ClientFailed extends Message
{
    private function __construct(private array $facts, private string $heading = 'Не получилось') {}

    public static function report(Request $request, array $data): void
    {
        try {
            if (($data['kind'] ?? null) === 'letter-closed') {
                self::letterClosed($request, $data);

                return;
            }
            $path = (string) (parse_url($data['url'], PHP_URL_PATH) ?: $data['url']);
            if (! Cache::add('client-failed:'.md5(preg_replace('/\d+/', 'N', $path).'|'.($data['status'] ?? '')), 1, now()->addMinutes(10))) {
                return;
            }
            $user = $request->user();
            NotifyOwner::dispatch(new self(array_values(array_filter([
                ($data['kind'] ?? 'frame') === 'form' ? 'Отправка формы' : 'Фрейм '.($data['frame'] ?: '—'),
                'GET '.$data['url'].' → '.($data['status'] ?? 'без ответа'),
                ! empty($data['redirected']) ? 'Увело на '.$data['redirected'] : null,
                $user ? $user->name.' (#'.$user->id.')' : 'Гость',
                'Хост '.$request->getHost(),
            ]))));
        } catch (Throwable $failed) {
            Log::warning('Отчёт «Не получилось» владельцу не ушёл', ['error' => $failed->getMessage()]);
        }
    }

    /**
     * Окно письма шага закрылось без отметки «сделано» (`window_controller`, `letter-closed`). 16.11.2026, владелец переслал
     * «Фрейм letters-frame, GET …/work/mail/new?offer=2611154094 → без ответа»: письмо 8381 при этом ушло — отчёт ложный.
     * Теперь — только когда по машине правда нет письма, и словами, о какой машине речь. Проверка — через 3 минуты очередью
     * (16.11.2026, Belgee X50 2611154126 и 2611154125: письма 8385 и 8386 записались через 4–10 с после закрытия окна — окно
     * ждёт настоящей отправки, а отчёт спрашивал сразу и выходил ложным).
     */
    private static function letterClosed(Request $request, array $data): void
    {
        $url = urldecode(urldecode($data['url']));
        $number = preg_match('/[?&]offer=(\d+)/', $url, $m) || preg_match('#/offers/(\d+)/#', $url, $m) ? (int) $m[1] : null;
        $userId = $request->user()?->id;
        $closedAt = now();
        dispatch(fn () => self::letterUnsent($number, $userId, $url, $closedAt))->delay(now()->addMinutes(3));
    }

    /** Отложенная проверка `letterClosed`: письма по машине нет с закрытия окна (и за 15 минут до) и шаг всё ещё ждёт его — владельцу. */
    public static function letterUnsent(?int $number, ?int $userId, string $url, \Illuminate\Support\Carbon $closedAt): void
    {
        $offer = $number ? Offer::where('number', $number)->first() : null;
        $sent = $offer && MailMessage::whereIn('thread_id', Thread::where('offer_id', $offer->id)->select('id'))
            ->where('direction', Direction::Out->value)->where('created_at', '>', $closedAt->copy()->subMinutes(15))->exists();
        $status = $offer?->position(Track::Sale)?->letterStatus();
        if ($sent || ($status !== null && ! in_array($status, ['unsent', 'failed', 'bounced'], true))) {
            return;
        }
        if (! Cache::add('client-failed:letter:'.($number ?? md5($url)), 1, now()->addMinutes(10))) {
            return;
        }
        $user = $userId ? \App\Users\User::find($userId) : null;
        NotifyOwner::dispatch(new self(array_values(array_filter([
            $offer ? trim($offer->title().' № '.$offer->number) : 'Машина не найдена: '.$url,
            $offer?->position(Track::Sale)?->stage?->name ? 'Шаг «'.$offer->position(Track::Sale)->stage->name.'»' : null,
            $user ? $user->name.' (#'.$user->id.')' : 'Гость',
        ])), 'Окно письма закрылось, письмо не ушло'));
    }

    public function ownerOnly(): bool
    {
        return true;
    }

    protected function title(): string
    {
        return $this->heading;
    }

    protected function lines(): array
    {
        return $this->facts;
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Открыть CRM', 'url' => Surface::Crm->url('/')];
    }
}
