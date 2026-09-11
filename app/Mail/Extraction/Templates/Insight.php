<?php

namespace App\Mail\Extraction\Templates;

use App\Mail\Extraction\Code;

/** ИНСАЙТ: тема «код VIN марка модель город», «Максимальное предложение N руб», фото ссылкой. */
final class Insight extends Template
{
    private const CODE = '/\b[А-ЯA-Z]{2}\d{2}[А-ЯA-Z]\d{6}\b/u';

    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $code = $this->match(self::CODE, $subject);
        $vin = $this->match(self::VIN, $subject);
        $this->put($fields, 'code', $code ? Code::normalize($code) : null, 'subject');
        $this->put($fields, 'vin', $vin, 'subject');
        $head = $subject;
        foreach (array_filter([$code, $vin]) as $part) {
            $head = trim((string) preg_replace('/'.preg_quote($part, '/').'/ui', '', $head, 1));
        }
        [$brand, $model] = $this->splitBrandModel($head);
        $this->put($fields, 'brand', $brand, 'subject');
        $this->put($fields, 'model', $model, 'subject');
        $this->put($fields, 'floor_price', $this->price($body), 'body');
        $this->put($fields, 'photos_url', preg_match('/https?:\/\/\S+/u', $body, $m) ? rtrim($m[0], '.,;') : null, 'body');

        return $fields;
    }
}
