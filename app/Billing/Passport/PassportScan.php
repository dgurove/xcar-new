<?php

namespace App\Billing\Passport;

use App\Mail\Scan\DocumentPhoto;
use App\Mail\Scan\Paper;
use App\Mail\Scan\ScanFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Скан страницы паспорта для `DocumentText`: тот же медиафайл, что `Paper`, но фото документа (повороты, увеличение). */
final class PassportScan implements DocumentPhoto, ScanFile
{
    private Paper $paper;

    public function __construct(public readonly Media $media)
    {
        $this->paper = new Paper($media);
    }

    public function scanId(): string
    {
        return $this->paper->scanId();
    }

    public function scanSha(): ?string
    {
        return $this->paper->scanSha();
    }

    public function scanName(): string
    {
        return $this->paper->scanName();
    }

    public function scanMime(): ?string
    {
        return $this->paper->scanMime();
    }

    public function scanSize(): int
    {
        return $this->paper->scanSize();
    }

    public function isInline(): bool
    {
        return false;
    }

    public function isImage(): bool
    {
        return $this->paper->isImage();
    }

    public function isPdf(): bool
    {
        return $this->paper->isPdf();
    }

    public function isPhoto(): bool
    {
        return false;
    }

    public function isOnDisk(): bool
    {
        return $this->paper->isOnDisk();
    }

    public function file(): ?string
    {
        return $this->paper->file();
    }

    public function fileKey(): string
    {
        return $this->paper->fileKey();
    }
}
