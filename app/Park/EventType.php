<?php

namespace App\Park;

enum EventType: string
{
    case Created = 'created';
    case Accepted = 'accepted';
    case Moved = 'moved';
    case Inspected = 'inspected';
    case Towed = 'towed';
    case Released = 'released';
    case Note = 'note';
    case Updated = 'updated';
    case DocSent = 'doc_sent';
    case DocBack = 'doc_back';
    case Scheduled = 'scheduled';
    case Departed = 'departed';
    case Assigned = 'assigned';
    case Cancelled = 'cancelled';
    case Linked = 'linked';
}
