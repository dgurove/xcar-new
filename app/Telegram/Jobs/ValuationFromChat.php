<?php

namespace App\Telegram\Jobs;

use App\Offers\Actions\ApplyValuations;
use App\Offers\Actions\MatchValuations;
use App\Offers\Pricing;
use App\Offers\ValuationPhoto;
use App\Offers\ValuationText;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Bot;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Скрин СМС с оценками — админу в основного бота (28.10.2026, владелец: «хочу эту пикчу просто телеграм-боту скидывать,
 * чтобы он сам понимал, что я хочу закупочные проставить, и проставлял; только для админов»). Фото (или текст «номер
 * сумма») читается тем же путём, что окно «Оценить → Из текста»: `ValuationPhoto` → `ValuationText` → `MatchValuations`.
 * Ответ — что по каждому номеру и кнопки «Проставить N» / «Завести N» (`UpdateHandler::valuation` → `ApplyValuations`),
 * без кнопки ничего не пишется. Очередь чтения — своим работником (`database-scan`): OCR 10–15 с, бот за это время не стоит.
 */
final class ValuationFromChat implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public int $chatId, public int $userId, public ?array $photo = null, public ?string $text = null)
    {
        $this->onConnection('database-scan')->onQueue('scan');
    }

    public function handle(Bot $bot, MatchValuations $match): void
    {
        $user = User::find($this->userId);
        if (! $user?->isAdmin()) {
            return;
        }
        $text = $this->text;
        if ($this->photo) {
            $bot->typing($this->chatId);
            $file = $bot->file($this->photo['file_id'], $this->photo['file_unique_id']);
            $text = $file ? ValuationPhoto::read(Storage::disk('private')->path($file[1]))['text'] : '';
        }
        $rows = ValuationText::parse((string) $text)['rows'];
        if (! collect($rows)->contains(fn ($r) => $r['amount'] !== null)) {
            $this->say($bot, $this->photo ? 'На фото не нашёл номеров убытка с суммами' : 'Не нашёл номеров убытка с суммами');

            return;
        }
        $items = $match($user, $rows);
        $id = random_int(100000, 999999999);
        Cache::put("valuation-bot:{$id}", ApplyValuations::prepare($user, $items) + ['chat' => $this->chatId], now()->addDay());
        $this->say($bot, self::summary($items), self::buttons($id, $items));
    }

    /** Что по каждому номеру: проставим (сумма закупочной), уже так, вписано иначе, проверить, нет в CRM. */
    public static function summary(array $items): string
    {
        $lines = [];
        foreach ($items as $i) {
            $car = $i['offer'] ? e($i['offer']->titleWithYear()) : null;
            $floor = $i['offer'] && $i['amount'] ? (Pricing::fromAmount($i['offer']->vendor_id, (int) $i['amount'])['floor_price'] ?? null) : null;
            $lines[] = match ($i['status']) {
                'fill' => '✅ '.e($i['ref']).' — '.$car.': закупочная '.Money::rub((int) $floor),
                'same' => '☑️ '.e($i['ref']).' — '.$car.': уже так',
                'conflict' => '⚠️ '.e($i['ref']).' — '.$car.': в CRM вписано иначе, решите в окне оценки',
                'absent' => '➕ '.e($i['ref']).': нет в CRM, '.Money::rub((int) $i['amount']),
                default => '❓ '.e($i['ref']).($car ? ' — '.$car : '').': проверьте в окне оценки',
            };
        }

        return '<b>Оценка из фото</b>'."\n".implode("\n", $lines);
    }

    /** @return list<list<array<string, string>>> */
    public static function buttons(int $id, array $items): array
    {
        $by = collect($items)->countBy('status');
        $row = array_values(array_filter([
            ($by['fill'] ?? 0) ? ['text' => 'Проставить '.$by['fill'], 'callback_data' => "valuation:{$id}:apply"] : null,
            ($by['absent'] ?? 0) ? ['text' => 'Завести '.$by['absent'], 'callback_data' => "valuation:{$id}:create"] : null,
        ]));

        return array_values(array_filter([$row ?: null, [['text' => 'Открыть в CRM', 'url' => Surface::Crm->url('/?preset=nofloor')]]]));
    }

    private function say(Bot $bot, string $text, ?array $keyboard = null): void
    {
        try {
            $bot->send($this->chatId, $text, $keyboard);
        } catch (Throwable $e) {
            Log::warning('Telegram: оценка из фото не ответила', ['chat' => $this->chatId, 'error' => $e->getMessage()]);
        }
    }
}
