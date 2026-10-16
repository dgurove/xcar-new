<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Telegram\Jobs\NotifyOwner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Необработанное исключение на запросе (то, что человек видит страницей 500): класс, текст, место в коде, адрес,
 * кто зашёл. Одинаковая ошибка (класс, файл, строка) — не чаще раза в 10 минут. Отказы по правам, 404, 419, 429
 * и ошибки полей сюда не доходят: их не отчитывает сам Laravel (`Handler::$internalDontReport`), а HTTP-ответы
 * ниже 500 отсекаются здесь.
 */
final class ServerError extends Message
{
    private function __construct(private array $facts, private ?string $url) {}

    /** Из `withExceptions()->reportable()`: сам отчёт не падает никогда — сломанные очередь или кэш не дают второй 500. */
    public static function report(Throwable $e): void
    {
        if (app()->runningInConsole() || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500)) {
            return;
        }
        try {
            if (! Cache::add('server-error:'.md5($e::class.'|'.$e->getFile().'|'.$e->getLine()), 1, now()->addMinutes(10))) {
                return;
            }
            $request = request();
            $user = $request->user();
            NotifyOwner::dispatch(new self([
                $e::class,
                Str::limit(trim($e->getMessage()), 500),
                Str::after($e->getFile(), base_path().'/').':'.$e->getLine(),
                $request->method().' '.$request->fullUrl(),
                $user ? $user->name.' (#'.$user->id.')' : 'Гость',
                'Хост '.$request->getHost(),
            ], $request->isMethodSafe() ? $request->fullUrl() : null));
        } catch (Throwable $failed) {
            Log::warning('Отчёт об ошибке владельцу не ушёл', ['error' => $failed->getMessage()]);
        }
    }

    protected function title(): string
    {
        return 'Ошибка 500';
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
        return ['text' => 'Открыть', 'url' => $this->url ?? Surface::Crm->url('/')];
    }
}
