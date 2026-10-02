<?php

namespace App\Mail\Scan;

/**
 * Файл, который читает «✨»: вложение письма (`Mail\Attachment`) или документ, загруженный в предложение руками
 * (`Paper` — медиа коллекции `papers`). `DocumentText`, задача `ScanAttachments` и окно работают с ним, не зная,
 * откуда файл.
 */
interface ScanFile
{
    /** Номер в окне, задаче и метке «читается»: `812` — вложение, `m45` — документ предложения. */
    public function scanId(): string;

    /** sha256 содержимого — имя в кеше текста: один скан из письма и из документов читается раз. null — не знаем. */
    public function scanSha(): ?string;

    public function scanName(): string;

    public function scanMime(): ?string;

    public function scanSize(): int;

    /** Картинка в теле письма — не документ. */
    public function isInline(): bool;

    public function isImage(): bool;

    public function isPdf(): bool;

    /** Фото — плиткой и без отметки в окне; документ — листом и отмечен. */
    public function isPhoto(): bool;

    /** Файл уже у нас на диске: разбор письма в ящик за ним не ходит. */
    public function isOnDisk(): bool;

    /** Абсолютный путь к файлу; null — файла нет. */
    public function file(): ?string;

    /** Тот же файл без отпечатка: имя без регистра и размер. */
    public function fileKey(): string;
}
