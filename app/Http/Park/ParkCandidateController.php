<?php

namespace App\Http\Park;

use App\Http\Admin\CandidateController;
use App\Mail\Scope;

/** «Из писем» на стоянке: тот же экран, «Завести» открывает форму заявки с полями из письма (`/requests/new?candidate=`). */
class ParkCandidateController extends CandidateController
{
    public function __construct()
    {
        parent::__construct(Scope::Park, '/requests/from-mail', '/mail');
    }
}
