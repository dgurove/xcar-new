<?php

namespace App\Telegram\Offers;

use App\Media\Jpeg;
use App\Offers\Offer;
use App\Offers\Share\Caption;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Карточка предложения в боте — одно сообщение (03.11.2026, владелец: «пдф, текст и ссылка-кнопка в одном сообщении»):
 * PDF первых 10 видимых кадров (`SharePdf::prepared`, собран заранее при публикации) с подписью для клиента
 * (`Caption::forward`: машина, город, характеристики, цена) и кнопкой «Открыть в XCar». Пересылается клиенту как есть.
 * «Срочно» и срок приёма не пишем ни в подписи, ни на кнопке (03.11.2026, владелец). Кадров нет — то же текстом. Шеринг
 * закрыт — как решил владелец 21.10.2026: одно фото с той же подписью и `protect_content`, в Telegram его не переслать и
 * не сохранить; PDF такой машины не собирается вовсе (`PrepareSharePdf`).
 *
 * Файл грузится в Telegram один раз (через туннель из Цюриха, `Bot::routeOptions`): дальше всем по `file_id` — кэш по
 * ключу PDF или версии кадра, сменились кадры — ключ другой.
 */
final class Card
{
    /** Сколько помнить `file_id`: дольше предложение в продаже не живёт. */
    private const DAYS = 30;

    /** Длинная сторона кадра закрытой машины: Telegram всё равно ужмёт больше. */
    private const SIDE = 1280;

    /** @return int id сообщения */
    public static function send(OffersBot $bot, int $chatId, Offer $offer, User $user, bool $silent): int
    {
        $caption = e(Caption::forward($offer));
        $locked = $offer->shareLockedFor($user);
        $extra = ['reply_markup' => json_encode(['inline_keyboard' => [[self::button($offer, $bot->logsIn())]]], JSON_UNESCAPED_UNICODE)]
            + ($silent ? ['disable_notification' => 'true'] : [])
            + ($locked ? ['protect_content' => 'true'] : []);
        if ($locked) {
            $photo = $offer->mainPhoto();

            return $photo
                ? self::upload($bot, 'photo', $chatId, 'offers-bot:photo:'.$photo->id.':'.($photo->updated_at?->timestamp ?? 0), fn () => self::jpeg($photo), $caption, $extra)
                : $bot->say($chatId, $caption, null, $extra);
        }
        $subject = Subject::offer($offer);
        $pdf = app(SharePdf::class);
        $key = $pdf->preparedKey($subject);

        return $key === null
            ? $bot->say($chatId, $caption, null, $extra)
            : self::upload($bot, 'document', $chatId, 'offers-bot:pdf:'.$offer->id.':'.$key,
                fn () => [(string) file_get_contents($pdf->prepared($subject)), $subject->fileName()], $caption, $extra);
    }

    /**
     * По `file_id` из кэша, а Telegram его забыл или кэша нет — файлом, и новый `file_id` в кэш.
     *
     * @param  'document'|'photo'  $type
     * @param  callable(): array{0: string, 1: string}  $file
     */
    private static function upload(OffersBot $bot, string $type, int $chatId, string $cache, callable $file, string $caption, array $extra): int
    {
        if ($fileId = Cache::get($cache)) {
            try {
                return $bot->media($type, $chatId, $fileId, $caption, $extra)['id'];
            } catch (RequestException $e) {
                // Telegram забыл файл — грузим заново; остальное — наверх.
                if ($e->response->status() !== 400 || ! str_contains(strtolower((string) $e->response->json('description')), 'file')) {
                    throw $e;
                }
                Cache::forget($cache);
            }
        }
        $bot->action($chatId, $type === 'photo' ? 'upload_photo' : 'upload_document');
        $sent = $bot->media($type, $chatId, $file(), $caption, $extra);
        if ($sent['file_id']) {
            Cache::put($cache, $sent['file_id'], now()->addDays(self::DAYS));
        }

        return $sent['id'];
    }

    /**
     * Кадр JPEG до 1280 px из нашего webp; не пережали — как есть, Telegram принимает и webp.
     *
     * @return array{0: string, 1: string} содержимое и имя
     */
    private static function jpeg(Media $media): array
    {
        $jpeg = Jpeg::fit($media, self::SIDE);

        return $jpeg !== null ? [$jpeg, 'offer-'.$media->id.'.jpg'] : [(string) file_get_contents($media->getPath()), $media->file_name];
    }

    private static function button(Offer $offer, bool $login): array
    {
        return self::site('Открыть в XCar', self::url((string) $offer->number, $login), $login);
    }

    /** Кнопка на сайт: входом через Telegram (`login_url`, нужен домен в BotFather) или простой ссылкой. */
    public static function site(string $text, string $url, bool $login): array
    {
        return $login ? ['text' => $text, 'login_url' => ['url' => $url, 'request_write_access' => false]] : ['text' => $text, 'url' => $url];
    }

    /**
     * Адрес на сайте: через вход по кнопке Telegram (`/telegram/open/…` проверит подпись и впустит) или прямой. Цель —
     * номер предложения или `offers` (каталог), а не путь: подпись Telegram не покрывает наш параметр, путь — не
     * перенаправление.
     */
    public static function url(string $target, bool $login): string
    {
        $target = ctype_digit($target) ? $target : 'offers';
        if ($login) {
            return Surface::Site->url('/telegram/open/'.$target);
        }

        return Surface::Site->url($target === 'offers' ? '/offers' : '/offers/'.$target);
    }
}
