<?php

namespace App\Mail\Scan;

use App\Mail\Attachment;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * Что распознаёт «✨»: цепочка «Из писем» парковки или CRM (`CandidateSubject`), заведённая ТС (`VehicleSubject`),
 * предложение CRM (`OfferSubject`). Окно, задача чтения и выбор полей одни на все — разное только откуда файлы, что уже
 * известно, какие поля бывают и куда класть выбранное.
 */
interface Subject
{
    /** `c:93`, `v:266`, `o:512` — в событии `scan` и в задаче. */
    public function key(): string;

    /** Адрес окна: `/requests/from-mail/93/scan`, `/cars/266/scan`, `/offers/1043/scan`. */
    public function url(): string;

    /** Почта своего хоста: `/mail` у парковки, `/work/mail` в CRM — миниатюры файлов в окне. */
    public function mail(): string;

    /** Тема хаба для события `scan`: окно слушают только те, кто его может открыть. */
    public function topic(): string;

    /** Поля, которые у предмета бывают (`ScanFields::LABELS`): у предложения нет госномера и стоимости. @return list<string> */
    public function fields(): array;

    public function title(): string;

    public function hasCar(): bool;

    public function vendor(): ?Vendor;

    public function ref(): ?string;

    /** Можно ли «Завести» из окна (цепочка парковки — да, ТС и предложение уже заведены). */
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
