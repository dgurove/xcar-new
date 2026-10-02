<?php

namespace App\Mail\Extraction\Templates;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\Patterns;

/**
 * ИНСАЙТ: тема «код VIN марка модель город», «Максимальное предложение N руб», фото ссылкой. Код — `CodeMatcher`
 * («АС26К166877»), а формы, которых он не знает («ВК25Т123456»), — своей: две буквы, две цифры, буква, шесть цифр.
 */
final class Insight extends Template
{
    private const CODE = '/\b[А-ЯA-Z]{2}\d{2}[А-ЯA-Z]\d{6}\b/u';

    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $code = $this->firstCode($subject) ?? (($raw = $this->match(self::CODE, $subject)) ? Code::normalize($raw) : null);
        $vin = $this->vin($subject);
        $this->put($fields, 'code', $code, 'subject');
        $this->put($fields, 'vin', $vin, 'subject');
        $head = $subject;
        if ($code) {
            $head = trim((string) preg_replace(Patterns::codeRegex($code), '', $head, 1));
        }
        if ($vin) {
            $head = trim((string) preg_replace('/'.preg_quote($vin, '/').'/ui', '', $head, 1));
        }
        [$brand, $model] = $this->splitBrandModel($head);
        $this->put($fields, 'brand', $brand, 'subject');
        $this->put($fields, 'model', $model, 'subject');
        $this->put($fields, 'floor_price', $this->price($body), 'body');
        $this->put($fields, 'photos_url', preg_match('/https?:\/\/\S+/u', $body, $m) ? rtrim($m[0], '.,;') : null, 'body');

        return $fields;
    }
}
