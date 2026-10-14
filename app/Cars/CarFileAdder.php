<?php

namespace App\Cars;

use Spatie\MediaLibrary\MediaCollections\FileAdder;

/**
 * Новый файл машины от её дорожки (`OnCar::addMedia`): метка дорожки (`park: true` у парковки) остаётся при любых своих
 * свойствах вызова — spatie `withCustomProperties` их заменяет, и метка терялась у документов из писем и актов.
 */
final class CarFileAdder extends FileAdder
{
    /** @var array<string, mixed> */
    private array $mark = [];

    /** @param  array<string, mixed>  $mark */
    public function markedAs(array $mark): self
    {
        $this->mark = $mark;

        return $this->withCustomProperties($this->customProperties);
    }

    public function withCustomProperties(array $customProperties): self
    {
        return parent::withCustomProperties([...$customProperties, ...$this->mark]);
    }
}
