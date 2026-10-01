<?php

namespace App\Mail\Scan;

use App\Mail\Attachment;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * Что распознаёт «✨»: цепочка «Из писем» (`CandidateSubject`) или заведённая ТС (`VehicleSubject`). Окно, задача
 * чтения и выбор полей одни на оба — разное только откуда файлы, что уже известно и куда класть выбранное.
 */
interface Subject
{
    /** `c:93` или `v:266` — в событии `scan` и в задаче. */
    public function key(): string;

    /** Адрес окна: `/requests/from-mail/93/scan`, `/cars/266/scan`. */
    public function url(): string;

    public function title(): string;

    public function hasCar(): bool;

    public function vendor(): ?Vendor;

    public function ref(): ?string;

    /** Можно ли «Завести» из окна (цепочка — да, ТС уже заведена). */
    public function creates(): bool;

    /** Входящие файлы, которые «✨» умеет прочитать, без повторов, документы первыми. @return Collection<int, Attachment> */
    public function files(): Collection;

    /** Что уже известно: поле → значение и откуда (`ScanFields::ofCandidate` / `ofVehicle`). */
    public function current(): array;

    /** Положить выбранное (`ScanFields::chosen`). */
    public function apply(array $chosen, User $by): void;

    /** После чтения файлов: свернуть цепочку заново или обновить дело. */
    public function refresh(): void;
}
