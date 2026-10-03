<?php

namespace App\Telegram\Offers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Кадр в Telegram — один раз: первый показ грузит JPEG 1280 (из нашего webp, GD), Telegram отдаёт `file_id`, дальше
 * все менеджеры получают карточку по нему мгновенно. Отдать Telegram адрес картинки нельзя: до сервера он не
 * достучится. Кадр повернули или заменили — версия (updated_at) другая, грузим заново. `file_id` у каждого бота свой.
 */
final class PhotoCache
{
    private const SIDE = 1280;

    /** @return int id сообщения */
    public static function send(OffersBot $bot, int $chatId, Media $media, string $caption, ?array $markup = null, array $extra = []): int
    {
        $version = (int) ($media->updated_at?->timestamp ?? 0);
        $table = DB::connection('pgsql_async')->table('telegram_files');
        $key = ['bot' => OffersBot::NAME, 'media_id' => $media->id, 'version' => $version];
        if ($fileId = (clone $table)->where($key)->value('file_id')) {
            try {
                return $bot->photo($chatId, $fileId, $caption, $markup, $extra)['id'];
            } catch (RequestException $e) {
                // Telegram забыл файл (бывает после долгого простоя) — грузим заново; остальное — наверх.
                if ($e->response->status() !== 400 || ! str_contains(strtolower((string) $e->response->json('description')), 'file')) {
                    throw $e;
                }
                (clone $table)->where($key)->delete();
            }
        }
        $bot->action($chatId, 'upload_photo');
        $sent = $bot->photo($chatId, self::jpeg($media), $caption, $markup, $extra);
        if ($sent['file_id']) {
            (clone $table)->insertOrIgnore($key + ['file_id' => $sent['file_id'], 'created_at' => now()]);
        }

        return $sent['id'];
    }

    /** @return array{0: string, 1: string} содержимое и имя */
    private static function jpeg(Media $media): array
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'tgph');
        $tmp = $base.'.jpg';
        try {
            Image::load($media->getPath())->fit(Fit::Max, self::SIDE, self::SIDE)->format('jpg')->quality(85)->save($tmp);

            return [(string) file_get_contents($tmp), 'offer-'.$media->id.'.jpg'];
        } catch (Throwable $e) {
            // Не пережали — отдаём как есть: Telegram принимает и webp.
            return [(string) file_get_contents($media->getPath()), $media->file_name];
        } finally {
            @unlink($tmp);
            @unlink($base);
        }
    }
}
