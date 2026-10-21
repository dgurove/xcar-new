<?php

namespace App\Telegram\Offers;

use App\Offers\Offer;
use App\Offers\Share\Caption;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Users\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Карточка для пересылки клиенту (21.10.2026, владелец: «как при шеринге — просто переслать клиенту»): PDF первых 10
 * видимых кадров (`SharePdf::prepared`, собран заранее при публикации) с подписью шеринга без VIN, меток, заявленной и
 * ссылки (`Caption::forward`). Кнопок нет — пересылается чистым. Файл грузится в Telegram один раз: дальше всем по
 * `file_id` (кэш по ключу PDF — сменились кадры, ключ другой). Нет готового PDF — собирается тут же.
 */
final class PdfCard
{
    /** Сколько помнить `file_id`: дольше предложение в продаже не живёт. */
    private const DAYS = 30;

    /** @return int|null id сообщения; null — кадров нет */
    public static function send(OffersBot $bot, int $chatId, Offer $offer, User $user): ?int
    {
        $subject = Subject::offer($offer);
        $pdf = app(SharePdf::class);
        $key = $pdf->preparedKey($subject);
        if ($key === null) {
            return null;
        }
        $caption = e(Caption::forward($offer, $user));
        $cache = 'offers-bot:pdf:'.$offer->id.':'.$key;
        if ($fileId = Cache::get($cache)) {
            try {
                return $bot->document($chatId, $fileId, $caption)['id'];
            } catch (RequestException $e) {
                // Telegram забыл файл — грузим заново; остальное — наверх.
                if ($e->response->status() !== 400 || ! str_contains(strtolower((string) $e->response->json('description')), 'file')) {
                    throw $e;
                }
                Cache::forget($cache);
            }
        }
        $bot->action($chatId, 'upload_document');
        $sent = $bot->document($chatId, [(string) file_get_contents($pdf->prepared($subject)), $subject->fileName()], $caption);
        if ($sent['file_id']) {
            Cache::put($cache, $sent['file_id'], now()->addDays(self::DAYS));
        }

        return $sent['id'];
    }
}
