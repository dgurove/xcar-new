<?php

namespace App\Chats;

/** Кто писал — факт на момент письма, не вывод из текущей роли. */
enum AuthorKind: string
{
    case Participant = 'participant';
    case Staff = 'staff';
    case System = 'system';
}
