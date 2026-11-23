<?php

namespace App\Http\Admin;

use App\Support\Help;
use Illuminate\Http\Request;

/** «Настройки → Как работать» (24.11.2026): кто что делает в сделке и как, по человеку (`Help`). */
final class HelpController
{
    public function index(Request $request)
    {
        $who = $request->query('who');

        return view('admin.help.index', ['who' => Help::who(is_string($who) ? $who : null, $request->user())]);
    }
}
