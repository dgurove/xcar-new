<?php

namespace App\Mail\Extraction;

use App\Cars\Names;
use App\Cars\Settlement;
use App\Mail\Extraction\Templates\Generic;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Scope;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Support\Phone;
use App\Vendors\Vendor;

/**
 * Машина из письма с предложением (offer@): настоящий отправитель → вендор по адресу или домену → его шаблон
 * разбора, дальше — как у парковки (`ParkExtractor`): марка только из словаря (`Names`), машина и город из темы по
 * кускам («473697-26 XCITE X-CROSS 8 Самара»), документы во вложениях (акт, договор комиссии, оценка с торгов,
 * исходное письмо .eml) — `AttachmentReader`. Письма идут пересылкой сотрудника: тема и отправитель — из цитаты.
 */
final class Extractor
{
    public function __construct(private ?AttachmentReader $reader = null) {}

    public function extract(?string $subject, ?string $body, ?string $fromEmail = null, ?\DateTimeInterface $on = null, iterable $attachments = []): array
    {
        if (Intent::isService($subject, $fromEmail)) {
            return [];
        }
        $text = QuotationStripper::strip($body);
        $sender = QuotationStripper::forwardedSender($body) ?? $fromEmail;
        $vendor = Vendor::forSender($sender, Scope::Offers);
        $class = $vendor?->parser?->templateClass() ?? Generic::class;
        /** @var Template $template */
        $template = new $class;
        $fields = $template->extract(trim((string) $subject), $text);
        $fields += Template::common(trim((string) $subject), $text, $on);
        $topic = Patterns::cleanSubject(QuotationStripper::forwardedSubject($body) ?? $subject);

        $this->car($fields, $topic);
        // Город из тела («Авто в Череповце») — только когда тема его не дала: тема у Совкомбанка точнее.
        if (! isset($fields['city']) && ($city = Template::city($text))) {
            $fields['city'] = ['value' => $city, 'source' => 'body'];
        }
        $this->bareCode($fields, $topic);
        $this->body($fields, $text);
        foreach ($this->documents($attachments) as $field => $value) {
            $fields[$field] ??= $value;
        }
        if ($vendor) {
            $fields['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
            $fields['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
        }
        $fields['sender'] = ['value' => $sender, 'source' => 'sender'];

        return $fields;
    }

    /**
     * Номер без года («Вывоз ГОТС 771027 г Кемерово», «1188064 Exeed VX Тольятти» — так Совкомбанк пишет в теме): номер
     * с годом берём из таблицы Кати или предложений, если такой ровно один. Иначе номера нет — угадывать год нельзя.
     */
    private function bareCode(array &$fields, string $topic): void
    {
        if (isset($fields['code']) || ! preg_match('/(?:^|ГОТС|делу|дело|уб\.?)\s*№?\s*(\d{5,7})(?![\d\/-])/ui', trim($topic), $m)) {
            return;
        }
        $like = $m[1].'/%';
        $codes = LegacyClaim::where('claim_ref', 'like', $like)->pluck('claim_ref')
            ->merge(Offer::where('claim_ref', 'like', $like)->pluck('claim_ref'))
            ->map(fn ($c) => Code::normalize($c))->unique()->values();
        if ($codes->count() === 1) {
            $fields['code'] = ['value' => $codes[0], 'source' => 'subject'];
        }
    }

    /**
     * Марка — только та, что есть в словаре: у шаблона «первое слово темы» бывает «Fwd», «на», «Вывоз».
     * Нет марки — ищем её в теме после номера; хвост после модели — город («Самара», «г. Челябинск (обл.)»).
     */
    private function car(array &$fields, string $topic): void
    {
        if (isset($fields['brand'])) {
            $found = Names::find(trim($fields['brand']['value'].' '.($fields['model']['value'] ?? '')));
            if ($found) {
                $fields['brand']['value'] = $found['brand']->name;
                $fields['model'] = ['value' => $found['model'] ?: ($fields['model']['value'] ?? null), 'source' => $fields['brand']['source']];
                if ($fields['model']['value'] === null) {
                    unset($fields['model']);
                }
            } else {
                unset($fields['brand'], $fields['model']);
            }
        }
        // Номера («473697-26», «AUT-26-530378», «Z691/046/03165/26») и служебные слова темы — не машина.
        $clean = (string) preg_replace(['/\S*\d[\d\/\-]{4,}\S*/u', '/\b(?:на\s+вывоз|вывоз\w*|по\s+делу|ГОТС|ТС)\b/ui'], ' ', $topic);
        $found = Names::find($clean);
        if (! $found) {
            // Машины в теме нет («на вывоз 677209-2026 : г Реутов Московская обл») — город всё равно там.
            if ($city = self::cityIn($clean)) {
                $fields['city'] ??= ['value' => $city, 'source' => 'subject'];
            }

            return;
        }
        [$model, $city] = $this->cityTail((string) $found['model'], (string) $found['after']);
        if (! isset($fields['brand'])) {
            $fields['brand'] = ['value' => $found['brand']->name, 'source' => 'subject'];
            if ($model !== '') {
                $fields['model'] = ['value' => $model, 'source' => 'subject'];
            }
        }
        if ($city) {
            $fields['city'] ??= ['value' => $city, 'source' => 'subject'];
        }
    }

    /**
     * Город в хвосте темы. Модели нет в справочнике — словарь отдаёт всё после марки моделью («Lx Магнитогорск»):
     * последнее слово, которое есть среди городов, уходит из модели в город.
     *
     * @return array{0: string, 1: ?string}
     */
    private function cityTail(string $model, string $after): array
    {
        $tail = trim((string) preg_replace('/\([^)]*\)|\b(?:г|гор)\.?\s+/u', ' ', $after));
        if ($tail !== '' && ($city = self::settlement($tail))) {
            return [$model, $city];
        }
        $words = preg_split('/\s+/u', trim($model)) ?: [];
        for ($n = min(2, count($words) - 1); $n >= 1; $n--) {
            $guess = implode(' ', array_slice($words, -$n));
            if ($city = self::settlement($guess)) {
                return [implode(' ', array_slice($words, 0, -$n)), $city];
            }
        }

        return [$model, self::cityIn($tail)];
    }

    /** Город из справочника среди слов строки — с конца, по два слова и по одному. */
    private static function cityIn(string $text): ?string
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\-]+/u', $text) ?: [], fn ($w) => mb_strlen($w) > 2));
        for ($i = count($words) - 1; $i >= 0; $i--) {
            foreach ([2, 1] as $n) {
                if ($i - $n + 1 >= 0 && ($city = self::settlement(implode(' ', array_slice($words, $i - $n + 1, $n))))) {
                    return $city;
                }
            }
        }

        return null;
    }

    /** Город справочника по имени (`Settlement::named`). */
    public static function settlement(string $name): ?string
    {
        return Settlement::named($name)['name'] ?? null;
    }

    /**
     * Цена без подписи шаблона: сумма с «руб / р. / ₽» в своих словах письма — голой строкой («1 259 990 руб.», так пишет
     * Совкомбанк) или внутри фразы. Берётся, только если разная сумма одна; пробег, «забрать за» (это `owner_price`)
     * и даты не в счёт.
     */
    private function body(array &$fields, string $text): void
    {
        $plain = Template::plain($text);
        if (! isset($fields['floor_price']) && preg_match_all('/(?<![\d.,])'.Template::MONEY.'\s*(?:руб|р\.|₽)/u', $plain, $m, PREG_OFFSET_CAPTURE)) {
            $prices = [];
            foreach ($m[1] as [$digits, $at]) {
                $before = mb_strtolower(mb_strcut($plain, max(0, $at - 60), min(60, $at)));   // смещение в байтах — режем по буквам
                if (preg_match('/(пробег\w*|забрать\s+за|км)\s*[:–—-]?\s*$/u', $before)) {
                    continue;
                }
                if ($value = Template::money($digits)) {
                    $prices[$value] = true;
                }
            }
            if (count($prices) === 1) {
                $fields['floor_price'] = ['value' => array_key_first($prices), 'source' => 'body'];
            }
        }
        if (preg_match('/готов\w*\s+к\s+передаче\s*[–—:\-]?\s*('.Patterns::PHONE.')\s*([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){0,2})?/u', $text, $m)) {
            $phone = Phone::normalize($m[1]);
            $fields['insured_phone'] ??= ['value' => $phone ? Phone::format($phone) : trim($m[1]), 'source' => 'body'];
            if (! empty($m[2])) {
                $fields['insured_name'] ??= ['value' => trim($m[2]), 'source' => 'body'];
            }
            $fields['request'] ??= ['value' => 'tow', 'source' => 'body'];
        }
    }

    /**
     * Поля из вложений: сначала акт и договор (там данные ПТС), потом оценка, потом исходное письмо.
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    private function documents(iterable $attachments): array
    {
        if (! $this->reader) {
            return [];
        }
        $rank = fn ($a) => match (true) {
            (bool) preg_match('/акт|договор/ui', (string) $a->filename) => 0,
            str_ends_with(mb_strtolower((string) $a->filename), '.pdf') => 1,
            default => 2,
        };
        $list = collect($attachments)->reject(fn ($a) => $a->is_inline)->sortBy($rank)->values();
        $fields = [];
        foreach ($list as $attachment) {
            foreach ($this->reader->read($attachment) as $field => $value) {
                $fields[$field] ??= $value;
            }
        }

        return $fields;
    }

    /** Код убытка с машиной — уже оффер; машина с ценой — тоже. Переписка по существующему офферу отсекается снаружи. */
    public static function looksLikeOffer(array $fields): bool
    {
        $hasCar = isset($fields['vin']) || isset($fields['plate']) || isset($fields['brand']);
        $hasCode = isset($fields['code']);

        return ($hasCode && $hasCar) || ($hasCar && isset($fields['floor_price']));
    }
}
