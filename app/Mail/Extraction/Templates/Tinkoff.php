<?php

namespace App\Mail\Extraction\Templates;

/** Т-Страхование: вся машина в теме, «Цена за Лот …», «Местонахождение …», фото архивом — в тело за номерами не лезем. */
final class Tinkoff extends Generic
{
    protected const BODY_FALLBACK = false;
}
