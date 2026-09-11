<?php

namespace App\Mail;

enum ParseState: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Failed = 'failed';
}
