<?php

namespace App\Mail\Reading;

use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Extractor;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Extraction\QuotationStripper;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Чтение письма — один раз. Результат лежит у письма (`parsed`: поля ТС и заявки, номера-тождества, смысл,
 * свои слова без цитат и подписи) с версией читалки; номера дублируются в `mail_message_keys` для индекса.
 * Ветки, цепочки «Из писем», привязка к ТС — всё считается из `parsed`, само письмо больше никто не разбирает.
 * Поменялись правила — поднять VERSION, `mail:read --stale` перечитает только старые письма.
 */
final class ReadLetter
{
    public const VERSION = 5;

    public function __construct(private ParkExtractor $park, private Extractor $offers, private CodeMatcher $codes) {}

    /** @return array{fields: array, keys: list<string>, intent: ?string, own_text: string} */
    public function read(Message $message): array
    {
        $message->loadMissing(['account', 'attachments']);
        $body = $message->text_body ?: strip_tags((string) $message->html_body);
        $isPark = $message->account?->scope === Scope::Park;
        $fields = $isPark
            ? $this->park->extract($message->subject, $body, $message->from_email, $message->date_at, $message->attachments->pluck('filename')->all(), $message->attachments)
            : $this->offers->extract($message->subject, $body, $message->from_email, $message->date_at);
        $intent = $isPark ? Intent::ofMessage($message) : null;
        $own = QuotationStripper::ownText($body);

        return [
            'fields' => $fields,
            'keys' => $this->keys($message, $fields, $intent, $isPark),
            'intent' => $intent?->value,
            'own_text' => mb_substr(Intent::excerpt($own !== '' ? $own : $body, 2000), 0, 2000),
        ];
    }

    /** Прочитать и сохранить: `parsed`, версия, смысл, номера в индексе. */
    public function apply(Message $message): array
    {
        $parsed = $this->read($message);
        DB::transaction(function () use ($message, $parsed) {
            $message->forceFill(['parsed' => $parsed, 'parser_version' => self::VERSION, 'intent' => $parsed['intent']])->saveQuietly();
            DB::table('mail_message_keys')->where('message_id', $message->id)->delete();
            if ($parsed['keys']) {
                DB::table('mail_message_keys')->insert(array_map(fn ($k) => ['message_id' => $message->id, 'key' => $k], $parsed['keys']));
            }
        });

        return $parsed;
    }

    /**
     * Тождества письма: номер убытка (все из темы — «выдать ТС А и Б» касается обеих машин), VIN, госномер.
     * Бухгалтерия и автоответы номеров не дают: отчёт-акт со списком VIN связал бы десятки цепочек.
     *
     * @return list<string>
     */
    private function keys(Message $message, array $fields, ?Intent $intent, bool $isPark): array
    {
        if (in_array($intent, [Intent::Billing, Intent::Auto], true)) {
            return [];
        }
        $keys = Candidate::identities($fields, null);
        if ($isPark) {
            foreach ($this->codes->findAll($message->subject) as $code) {
                $keys[] = 'code:'.Code::key($code);
            }
        }

        return array_values(array_unique($keys));
    }
}
