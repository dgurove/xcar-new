<?php

namespace App\Mail\Extraction\Templates;

use App\Mail\Extraction\Patterns;

/** ИНСАЙТ: тема «код VIN марка модель город», «Максимальное предложение N руб», фото ссылкой. Код — `CodeMatcher`. */
final class Insight extends Template
{
    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $code = $this->firstCode($subject);
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
