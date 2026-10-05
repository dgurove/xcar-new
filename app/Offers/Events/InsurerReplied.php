<?php

namespace App\Offers\Events;

use App\Mail\Message;
use App\Offers\Deal;
use Illuminate\Foundation\Events\Dispatchable;

/** Страховая ответила по идущей сделке письмом на deal@ (`OnMessage`): менеджеру — текст ответа, сделке — шаг дальше. */
final class InsurerReplied
{
    use Dispatchable;

    /** @param  bool  $live  письмо пришло сейчас; false — разбор прошлых писем: страховой сами не пишем */
    public function __construct(public Deal $deal, public Message $message, public bool $live = true) {}
}
