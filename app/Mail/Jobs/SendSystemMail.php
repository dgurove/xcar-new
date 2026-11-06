<?php

namespace App\Mail\Jobs;

use App\Mail\SystemMail;
use App\Vendors\Actions\MailClaimDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Системное письмо, которое обязано дойти (`SystemMail::queue`): четыре попытки — сразу и через 1, 5 и 15 минут. Не ушло и
 * после них — `failed`: по `meta` (номера «К запросу» — в Telegram получателю, `MailClaimDigest::undelivered`).
 */
final class SendSystemMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public string $email, public array $meta = [])
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $email = unserialize($this->email, ['allowed_classes' => true]);
        $sender = SystemMail::sender();
        if (! $sender) {
            Log::info('Системное письмо: отправлять нечем, только в журнал', ['subject' => $email->getSubject(), 'meta' => $this->meta]);

            return;
        }
        [$transport, $from] = $sender;
        $transport->send($email->from($from));
        Log::info('Системное письмо ушло', ['to' => $email->getTo()[0]?->getAddress(), 'subject' => $email->getSubject()]);
        // Письмо ушло — дальше ничто не должно ронять задачу: повтор отправил бы его второй раз.
        try {
            if (($this->meta['kind'] ?? null) === 'claim-digest' && isset($this->meta['to'])) {
                app(MailClaimDigest::class)->delivered((int) $this->meta['request'], (int) $this->meta['to']);
            }
        } catch (Throwable $e) {
            Log::warning('Системное письмо ушло, отметка после — нет: '.$e->getMessage(), ['meta' => $this->meta]);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Системное письмо не ушло и после повторов: '.($e?->getMessage() ?? ''), ['meta' => $this->meta]);
        if (($this->meta['kind'] ?? null) === 'claim-digest') {
            app(MailClaimDigest::class)->undelivered((int) $this->meta['request'], isset($this->meta['to']) ? (int) $this->meta['to'] : null);
        }
    }
}
