<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Telegram\Jobs\NotifyOwner;
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
    private function __construct(private array $facts) {}

    public static function report(Request $request, array $data): void
    {
        try {
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

    public function ownerOnly(): bool
    {
        return true;
    }

    protected function title(): string
    {
        return 'Не получилось';
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
