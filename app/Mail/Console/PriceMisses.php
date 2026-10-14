<?php

namespace App\Mail\Console;

use App\Mail\Direction;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Console\Command;

/**
 * Письма с предложениями, где сумма в тексте есть, а закупочная не распознана (14.10.2026: «Сумма за лот», «Стоимость
 * лота» Т-Страхования шаблон не знал, и черновики вставали в «Без закупочной» молча). Новая форма — в словарь цены лота
 * `Template::price`, потом `ReadLetter::VERSION` вверх, `mail:read` и `offers:fill-from-letters`.
 */
class PriceMisses extends Command
{
    protected $signature = 'mail:price-misses {--days=14}';

    protected $description = 'Письма с предложениями: сумма в тексте есть, закупочной нет';

    public function handle(): int
    {
        $letters = Message::query()->where('direction', Direction::In)->where('date_at', '>', now()->subDays((int) $this->option('days')))
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))
            ->whereNull('frozen_at')->orderBy('id')->get();
        $missed = 0;
        foreach ($letters as $letter) {
            if (! $letter->field('code') || $letter->field('floor_price')) {
                continue;
            }
            $text = Template::plain((string) ($letter->parsed['own_text'] ?? ''));
            if (! preg_match('/[^\n]{0,40}'.Template::MONEY.'\s*(?:руб|р\.|₽)[^\n]{0,20}/u', $text, $m)) {
                continue;
            }
            $missed++;
            $this->line("{$letter->id} {$letter->field('vendor')} {$letter->field('code')}: ".trim($m[0]));
        }
        $this->info("Без закупочной при сумме в тексте: {$missed} из {$letters->count()} писем");

        return self::SUCCESS;
    }
}
