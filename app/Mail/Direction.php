<?php

namespace App\Mail;

enum Direction: string
{
    case In = 'in';
    case Out = 'out';
}
