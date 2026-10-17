<?php

namespace App\Logistics;

/** Вывоз от владельца (созвон, документы, приём с фото и ключами) или перевозка A → B (перевод между парковками). */
enum TransportKind: string
{
    case Pickup = 'pickup';
    case Move = 'move';
}
