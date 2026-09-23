<?php

namespace App\Park;

use App\Mail\Message;
use Illuminate\Support\Carbon;

/** Шаг таймлайна дела: что сделано, что делать сейчас, что впереди. */
final class Step
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const TODO = 'todo';

    public const NEXT = 'next';

    /**
     * @param  list<string>  $chips  факты сделанного шага (дата, кто, куда)
     * @param  ?array{kind: string, label: string, url?: string}  $plate  главная кнопка плашки: submit (act-form), spawn (spawn-form), window (окно писем)
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $state,
        public ?string $hint = null,
        public ?Carbon $at = null,
        public array $chips = [],
        public ?array $plate = null,
        public ?Request $request = null,
        public bool $danger = false,
        /** Письмо, которое ждёт ответа: у шага `reply` своё, у остальных пусто. */
        public ?Message $ask = null,
    ) {}

    public function isCurrent(): bool
    {
        return $this->state === self::CURRENT;
    }

    public function isDone(): bool
    {
        return $this->state === self::DONE;
    }
}
