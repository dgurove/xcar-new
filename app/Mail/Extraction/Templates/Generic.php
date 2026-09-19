<?php

namespace App\Mail\Extraction\Templates;

/** Незнакомая страховая: всё, что находится в теме, потом — чего в теме не бывает — из тела. */
class Generic extends Template
{
    /** Искать номер, VIN, госномер и год ещё и в теле — там, где тема бывает пустой. */
    protected const BODY_FALLBACK = true;

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
        if (static::BODY_FALLBACK) {
            $this->put($fields, 'code', $this->firstCode($body), 'body');
            $this->put($fields, 'vin', $this->match(self::VIN, $body), 'body');
            $this->put($fields, 'plate', $this->match(self::PLATE, $body), 'body');
            $this->put($fields, 'year', $this->match(self::YEAR, $body), 'body');
        }
        $this->put($fields, 'floor_price', $this->price($body), 'body');
        $this->put($fields, 'location', $this->location($body), 'body');

        return $fields;
    }
}
