<?php

namespace App\Http\Park;

use App\Mail\Scope;

/** Почта стоянки — та же почта, свой ящик и свой адрес. */
class MailController extends \App\Http\Admin\MailController
{
    public function __construct()
    {
        parent::__construct(Scope::Park, '/mail');
    }
}
