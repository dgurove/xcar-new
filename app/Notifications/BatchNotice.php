<?php

namespace App\Notifications;

use App\Support\Plural;

/**
 * Сводное уведомление пачки (`Batch`): «Срок вышел: 7 шагов», ниже первые названия «и ещё N», ведёт в список. Категория,
 * тишина и Telegram — как у уведомлений, из которых пачка (`$sample`): выключил категорию — не приходит и сводное.
 */
final class BatchNotice extends Notice
{
    /** @param list<string> $lines */
    public function __construct(private string $key, private array $lines, private Notice $sample) {}

    public function title(): string
    {
        [$title, $forms] = Batch::KINDS[$this->key];
        $n = count($this->lines);

        return sprintf($title, $n.' '.Plural::of($n, $forms));
    }

    public function text(): ?string
    {
        // Одинаковые строки (две «Lada Granta») — один раз; «и ещё» — от числа событий.
        $shown = array_slice(array_values(array_unique($this->lines)), 0, 3);
        $rest = count($this->lines) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' и ещё '.$rest : '');
    }

    public function href(): string
    {
        return Batch::href($this->key);
    }

    /** Тема — вид пачки: следующая пачка того же вида сворачивает прежнюю строку ленты. */
    public function subject(): string
    {
        return '/batch/'.$this->key;
    }

    public function category(): string
    {
        return $this->sample->category();
    }

    public function quiet(): bool
    {
        return $this->sample->quiet();
    }

    public function important(): bool
    {
        return false;
    }

    public function toTelegram(): ?array
    {
        return $this->sample->toTelegram() === null ? null : ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть'];
    }
}
