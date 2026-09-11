<?php

namespace App\Mail\Extraction\Templates;

/** Энергогарант: тема «код, ТС Марка Модель / VIN», цены в первом письме нет. */
final class Energogarant extends Template
{
    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $this->put($fields, 'code', $this->firstCode($subject), 'subject');
        $this->put($fields, 'vin', $this->match(self::VIN, $subject), 'subject');
        [$brand, $model] = $this->brandAndModel($subject);
        $this->put($fields, 'brand', $brand, 'subject');
        $this->put($fields, 'model', $model, 'subject');

        return $fields;
    }

    protected function brandAndModel(string $text): array
    {
        if (preg_match('/ТС\s+(.+?)\s*\/\s*[A-HJ-NPR-Z0-9]{17}\b/ui', $text, $m)) {
            return $this->splitBrandModel($m[1]);
        }

        return parent::brandAndModel($text);
    }
}
