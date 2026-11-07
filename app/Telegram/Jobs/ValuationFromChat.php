<?php

namespace App\Telegram\Jobs;

use App\Offers\Actions\ApplyValuations;
use App\Offers\Actions\MatchValuations;
use App\Offers\Pricing;
use App\Offers\ValuationPhoto;
use App\Offers\ValuationSheet;
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
 * чтобы он сам понимал, что я хочу закупочные проставить, и проставлял; только для админов»). Фото, таблица Excel
 * (03.11.2026, как в окне) или текст «номер сумма» читаются тем же путём, что окно «Оценить → Из текста»:
 * `ValuationPhoto` / `ValuationSheet` → `ValuationText` → `MatchValuations`; подпись к файлу — вместе с ним. Ответ — что по
 * каждому номеру и кнопки «Проставить N» / «Завести N» (`UpdateHandler::valuation` → `ApplyValuations`), без кнопки
 * ничего не пишется. Очередь чтения — своим работником (`database-scan`): OCR 10–15 с, бот за это время не стоит.
 */
final class ValuationFromChat implements ShouldQueue
{
    use Queueable;

    public const PHOTO = 'photo';

    public const SHEET = 'sheet';

    public int $timeout = 120;

    /** @param  array{kind: string, file_id: string, file_unique_id: string}|null  $file */
    public function __construct(public int $chatId, public int $userId, public ?array $file = null, public ?string $text = null)
    {
        $this->onConnection('database-scan')->onQueue('scan');
    }

    public function handle(Bot $bot, MatchValuations $match): void
    {
        $user = User::find($this->userId);
        if (! $user?->isAdmin()) {
            return;
        }
        $kind = $this->file['kind'] ?? null;
        $text = (string) $this->text;
        if ($this->file) {
            $bot->typing($this->chatId);
            $got = $bot->file($this->file['file_id'], $this->file['file_unique_id']);
            $read = $got ? self::read($kind, Storage::disk('private')->path($got[1])) : '';
            $text = trim($read."\n".$text);
        }
        $rows = ValuationText::parse($text)['rows'];
        if (! collect($rows)->contains(fn ($r) => $r['amount'] !== null)) {
            $this->say($bot, match ($kind) {
                self::PHOTO => 'На фото не нашёл номеров убытка с суммами',
                self::SHEET => 'В таблице не нашёл номеров убытка с суммами',
                default => 'Не нашёл номеров убытка с суммами',
            });

            return;
        }
        $items = $match($user, $rows);
        $id = random_int(100000, 999999999);
        Cache::put("valuation-bot:{$id}", ApplyValuations::prepare($user, $items) + ['chat' => $this->chatId], now()->addDay());
        $this->say($bot, self::summary($items, match ($kind) {
            self::PHOTO => 'Оценка из фото',
            self::SHEET => 'Оценка из таблицы',
            default => 'Оценка из текста',
        }), self::buttons($id, $items));
    }

    /** Текст файла: таблица — `ValuationSheet` (битая — пусто, как в окне), картинка — OCR. */
    private static function read(?string $kind, string $path): string
    {
        if ($kind !== self::SHEET) {
            return ValuationPhoto::read($path)['text'];
        }
        try {
            return ValuationSheet::read($path)['text'];
        } catch (Throwable $e) {
            Log::info('Telegram: таблица оценок не прочиталась', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /** Что по каждому номеру: проставим (сумма закупочной), уже так, вписано иначе, проверить, нет в CRM. */
    public static function summary(array $items, string $title): string
    {
        $lines = [];
        foreach ($items as $i) {
            $car = $i['offer'] ? e($i['offer']->titleWithYear()) : null;
            $floor = $i['offer'] && $i['amount'] ? (Pricing::fromAmount($i['offer']->vendor_id, (int) $i['amount'], $i['offer'])['floor_price'] ?? null) : null;
            $lines[] = $i['suspicious'] && in_array($i['status'], ['fill', 'absent'], true)
                ? '⚠️ '.e($i['ref']).($car ? ' — '.$car : '').': '.e($i['suspicious']).', решите в окне оценки'
                : match ($i['status']) {
                    'fill' => '✅ '.e($i['ref']).($car ? ' — '.$car : '').': закупочная '.Money::rub((int) $floor),
                    'same' => '☑️ '.e($i['ref']).($car ? ' — '.$car : '').': уже так',
                    'conflict' => '⚠️ '.e($i['ref']).($car ? ' — '.$car : '').': в CRM вписано иначе, решите в окне оценки',
                    'absent' => '➕ '.e($i['ref']).': нет в CRM, '.Money::rub((int) $i['amount']),
                    default => '❓ '.e($i['ref']).($car ? ' — '.$car : '').': проверьте в окне оценки',
                };
        }

        return '<b>'.e($title).'</b>'."\n".implode("\n", $lines);
    }

    /** @return list<list<array<string, string>>> */
    public static function buttons(int $id, array $items): array
    {
        // Подозрительные суммы кнопками не берутся (`MatchValuations::suspicious`).
        $by = collect($items)->reject(fn ($i) => $i['suspicious'])->countBy('status');
        $row = array_values(array_filter([
            ($by['fill'] ?? 0) ? ['text' => 'Проставить '.$by['fill'], 'callback_data' => "valuation:{$id}:apply"] : null,
            ($by['absent'] ?? 0) ? ['text' => 'Завести '.$by['absent'], 'callback_data' => "valuation:{$id}:create"] : null,
        ]));

        return array_values(array_filter([$row ?: null, [['text' => 'Открыть в CRM', 'url' => Surface::Crm->url('/offers?preset=nofloor')]]]));
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
