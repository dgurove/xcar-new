<?php

namespace App\Mail\Extraction;

use App\Cars\Names;
use App\Cars\Settlement;
use App\Mail\Extraction\Templates\Generic;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Scope;
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
    /** Служебные письма почтовых сервисов («Вход с нового устройства») — не предложение, полей не дают. */
    private const SERVICE_SENDERS = '/^(?:security|noreply|no-reply|no_reply|notify|notifications?|mailer-daemon|postmaster|robot)@/i';

    private const SERVICE_SUBJECTS = '/вход с нового устройства|пытаются войти|восстановлени\w* пароля|подтвердите (?:адрес|почту)|автоответ|out of office|undeliver|не доставлено/iu';

    public function __construct(private ?AttachmentReader $reader = null) {}

    public static function isService(?string $subject, ?string $fromEmail): bool
    {
        return preg_match(self::SERVICE_SENDERS, (string) $fromEmail) === 1 || preg_match(self::SERVICE_SUBJECTS, (string) $subject) === 1;
    }

    public function extract(?string $subject, ?string $body, ?string $fromEmail = null, ?\DateTimeInterface $on = null, iterable $attachments = []): array
    {
        if (self::isService($subject, $fromEmail)) {
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
        $topic = trim((string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', QuotationStripper::forwardedSubject($body) ?? (string) $subject));

        $this->car($fields, $topic);
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

    /** Город справочника по имени: без регистра, «ё» как «е». */
    public static function settlement(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .,;:");
        if ($name === '' || mb_strlen($name) > 40) {
            return null;
        }

        return Settlement::whereRaw("replace(lower(name), 'ё', 'е') = ?", [str_replace('ё', 'е', mb_strtolower($name))])->value('name');
    }

    /** Совкомбанк в теле пишет цену голой строкой «1 259 990 руб.» и «Готовы к передаче – 8-903-333-18-94 Екатерина». */
    private function body(array &$fields, string $text): void
    {
        if (! isset($fields['floor_price']) && preg_match_all('/^[\s>*_]*(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d{5,8})\s*(?:руб\.?|р\.|₽)[\s*_]*$/um', $text, $m)) {
            $prices = array_unique(array_map(fn ($v) => CarWords::digits($v), $m[1]));
            if (count($prices) === 1) {
                $fields['floor_price'] = ['value' => reset($prices), 'source' => 'body'];
            }
        }
        if (preg_match('/готов\w*\s+к\s+передаче\s*[–—:\-]?\s*((?:\+7|8)[\d\s\-()]{9,16}\d)\s*([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){0,2})?/u', $text, $m)) {
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
