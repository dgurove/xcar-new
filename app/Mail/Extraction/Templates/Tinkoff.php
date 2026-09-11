<?php

namespace App\Mail\Extraction\Templates;

/** Т-Страхование: вся машина в теме, «Цена за Лот …», «Местонахождение …», фото архивом. */
final class Tinkoff extends Template
{
    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $this->put($fields, 'code', $this->firstCode($subject), 'subject');
        $this->put($fields, 'year', $this->match(self::YEAR, $subject), 'subject');
        $this->put($fields, 'vin', $this->match(self::VIN, $subject), 'subject');
        $this->put($fields, 'plate', $this->match(self::PLATE, $subject), 'subject');
        [$brand, $model] = $this->brandAndModel($subject);
        $this->put($fields, 'brand', $brand, 'subject');
        $this->put($fields, 'model', $model, 'subject');
        $this->put($fields, 'floor_price', $this->price($body), 'body');
        $this->put($fields, 'location', $this->location($body), 'body');

        return $fields;
    }
}
