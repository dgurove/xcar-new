<?php

namespace App\Cars\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Машина поменялась (поля или файлы) — из любой двери: CRM, парковка, «✨», письмо, Мигторг. Бросается после коммита
 * (`Car::saved`, файлы — `Live\RefreshCar::media`); по нему все экраны, где эта машина видна, перечитываются
 * (`Live\RefreshCar`).
 */
final class CarChanged
{
    use Dispatchable;

    /** @param  list<string>  $fields  что поменялось; файлы — `photos` */
    public function __construct(public int $carId, public array $fields) {}
}
