<?php

namespace App\Telegram\Offers;

use App\Offers\Offer;
use App\Offers\Share\Caption;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Support\Ago;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Карточка предложения в боте — одно сообщение (03.11.2026, владелец: «пдф, текст и ссылка-кнопка в одном сообщении»):
 * PDF первых 10 видимых кадров (`SharePdf::prepared`, собран заранее при публикации) с подписью для клиента
 * (`Caption::forward`: машина, город, характеристики, цена) и кнопкой «Открыть в XCar». Пересылается клиенту как есть.
 * Срочное — словами на кнопке, подпись для клиента остаётся чистой. Кадров нет — то же текстом. Шеринг закрыт — тот же
 * PDF с `protect_content`: в Telegram его не переслать и не сохранить.
 *
 * Файл грузится в Telegram один раз (через туннель из Цюриха, `Bot::routeOptions`): дальше всем по `file_id` — кэш по
 * ключу PDF, сменились кадры — ключ другой.
 */
final class Card
{
    /** Сколько помнить `file_id`: дольше предложение в продаже не живёт. */
    private const DAYS = 30;

    /** @return int id сообщения */
    public static function send(OffersBot $bot, int $chatId, Offer $offer, User $user, bool $silent): int
    {
        $caption = e(Caption::forward($offer));
        $extra = ['reply_markup' => json_encode(['inline_keyboard' => [[self::button($offer, $bot->logsIn())]]], JSON_UNESCAPED_UNICODE)]
            + ($silent ? ['disable_notification' => 'true'] : [])
            + ($offer->shareLockedFor($user) ? ['protect_content' => 'true'] : []);
        $subject = Subject::offer($offer);
        $pdf = app(SharePdf::class);
        $key = $pdf->preparedKey($subject);
        if ($key === null) {
            return $bot->say($chatId, $caption, null, $extra);
        }
        $cache = 'offers-bot:pdf:'.$offer->id.':'.$key;
        if ($fileId = Cache::get($cache)) {
            try {
                return $bot->document($chatId, $fileId, $caption, $extra)['id'];
            } catch (RequestException $e) {
                // Telegram забыл файл — грузим заново; остальное — наверх.
                if ($e->response->status() !== 400 || ! str_contains(strtolower((string) $e->response->json('description')), 'file')) {
                    throw $e;
                }
                Cache::forget($cache);
            }
        }
        $bot->action($chatId, 'upload_document');
        $sent = $bot->document($chatId, [(string) file_get_contents($pdf->prepared($subject)), $subject->fileName()], $caption, $extra);
        if ($sent['file_id']) {
            Cache::put($cache, $sent['file_id'], now()->addDays(self::DAYS));
        }

        return $sent['id'];
    }

    /** «Открыть в XCar»; у срочного — «🔥 Срочно, приём до …»: срок нужен менеджеру, а не клиенту. */
    private static function button(Offer $offer, bool $login): array
    {
        $text = $offer->isUrgent() ? '🔥 Срочно, приём до '.Ago::day($offer->bids_close_at, true) : 'Открыть в XCar';

        return self::site($text, self::url((string) $offer->number, $login), $login);
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
