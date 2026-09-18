<?php

namespace App\Park\Events;

use App\Mail\Candidate;
use Illuminate\Foundation\Events\Dispatchable;

/** Письмо на стоянку разобрано в кандидата: заявка на вывоз или приём ждёт человека. */
final class CandidateArrived
{
    use Dispatchable;

    public function __construct(public Candidate $candidate) {}
}
