<?php

namespace App\Mail\Scan;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документ предложения для «✨»: медиа коллекции `papers` (закрытый диск). Загруженное руками фото тоже документ —
 * человек положил его в документы. Отпечаток — `sha` из медиатеки (письма, архивы и загрузка с 02.10.2026 его пишут).
 */
final class Paper implements ScanFile
{
    public function __construct(public readonly Media $media) {}

    /** @param  iterable<Media>  $media  @return list<self> */
    public static function wrap(iterable $media): array
    {
        return array_values(array_map(fn (Media $m) => new self($m), [...$media]));
    }

    public function scanId(): string
    {
        return 'm'.$this->media->id;
    }

    public function scanSha(): ?string
    {
        return $this->media->getCustomProperty('sha');
    }

    public function scanName(): string
    {
        return (string) $this->media->file_name;
    }

    public function scanMime(): ?string
    {
        return $this->media->mime_type;
    }

    public function scanSize(): int
    {
        return (int) $this->media->size;
    }

    public function isInline(): bool
    {
        return false;
    }

    public function isImage(): bool
    {
        $mime = (string) $this->media->mime_type;

        return (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || preg_match('/\.hei[cf]$/i', $this->scanName()) === 1;
    }

    public function isPdf(): bool
    {
        return $this->media->mime_type === 'application/pdf';
    }

    public function isPhoto(): bool
    {
        return false;
    }

    public function isOnDisk(): bool
    {
        return $this->file() !== null;
    }

    public function file(): ?string
    {
        $path = $this->media->getPath();

        return is_file($path) ? $path : null;
    }

    public function fileKey(): string
    {
        return mb_strtolower($this->scanName()).'|'.$this->scanSize();
    }
}
