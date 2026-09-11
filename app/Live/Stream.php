<?php

namespace App\Live;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Ответ Turbo Stream: фрагменты страницы вместо целой страницы. */
final class Stream
{
    public const TYPE = 'text/vnd.turbo-stream.html';

    public static function wants(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), self::TYPE);
    }

    public static function response(string $html): Response
    {
        return response($html, 200, ['Content-Type' => self::TYPE.'; charset=utf-8']);
    }

    public static function view(string $view, array $data = []): Response
    {
        return self::response(view($view, $data)->render());
    }
}
