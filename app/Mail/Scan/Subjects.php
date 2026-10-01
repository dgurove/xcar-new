<?php

namespace App\Mail\Scan;

use App\Mail\Candidate;
use App\Park\Vehicle;

/** Предмет «✨» по ключу из задачи: `c:93` — цепочка, `v:266` — ТС. */
final class Subjects
{
    public static function find(string $key): ?Subject
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);

        return match ($kind) {
            'c' => ($c = Candidate::find((int) $id)) ? new CandidateSubject($c) : null,
            'v' => ($v = Vehicle::find((int) $id)) ? new VehicleSubject($v) : null,
            default => null,
        };
    }
}
