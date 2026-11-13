<?php

namespace App\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Имя документа для людей (13.11.2026, Сергей с парковки: «карандашик, чтобы переименовать прямо в системе, а не скачивать,
 * удалять, переименовывать, подгружать»): `custom_properties.title` с расширением файла, иначе имя файла. Файл на диске не
 * трогаем — путь к нему остаётся. Одно имя у списка, шторки документов и скачивания (`Site\FileController`).
 */
final class DocName
{
    public const MAX = 120;

    public static function of(Media $media): string
    {
        $title = trim((string) $media->getCustomProperty('title'));
        if ($title === '') {
            return (string) $media->file_name;
        }
        $ext = strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION));

        return $ext !== '' && ! str_ends_with(mb_strtolower($title), '.'.$ext) ? $title.'.'.$ext : $title;
    }

    /** Новое имя без пути и расширения, до `MAX` знаков; пустое — снова имя файла. Возвращает [было, стало]. */
    public static function rename(Media $media, string $name): array
    {
        $before = self::of($media);
        $ext = strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION));
        $clean = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name));
        if ($ext !== '' && str_ends_with(mb_strtolower($clean), '.'.$ext)) {
            $clean = mb_substr($clean, 0, -mb_strlen($ext) - 1);
        }
        $clean = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $clean)), 0, self::MAX);
        $clean === '' ? $media->forgetCustomProperty('title') : $media->setCustomProperty('title', $clean);
        // Короткая подпись PDF из фото («СТС» к «СТС Z691-….pdf») — после переименования не нужна: имя дал человек.
        $media->forgetCustomProperty('label');
        $media->save();

        return [$before, self::of($media)];
    }
}
