<?php

namespace App\Telegram\Messages;

use Illuminate\Support\Carbon;

/** Сообщение владельцу: заголовок, строки, кнопки решения и ссылка в CRM. */
abstract class Message
{
    abstract protected function title(): string;

    /** @return list<?string> */
    abstract protected function lines(): array;

    /** @return list<array{text: string, callback_data: string}> */
    abstract protected function decisions(): array;

    /** @return array{text: string, url: string} */
    abstract protected function link(): array;

    public function text(?string $trace = null): string
    {
        $lines = array_filter($this->lines(), fn ($l) => $l !== null && $l !== '');
        $text = '<b>'.e($this->title()).'</b>'."\n".implode("\n", array_map(fn ($l) => e($l), $lines));

        return $trace ? $text."\n\n".e($trace) : $text;
    }

    /** Кнопки решения по две в ряд, ссылка в CRM последней строкой. */
    public function keyboard(): array
    {
        return [...array_chunk($this->decisions(), 2), [$this->link()]];
    }

    public function afterDecision(): array
    {
        return [[$this->link()]];
    }

    protected static function moment(\DateTimeInterface $at): string
    {
        return Carbon::instance($at)->timezone(config('app.timezone'))->translatedFormat('j M, H:i');
    }
}
