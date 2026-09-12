<?php

namespace App\Http\Admin;

use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Template;
use App\Workflow\Insurer;

/** Хаб настроек: справочное, что меняется редко, — плитками со счётчиками. */
class SettingsController
{
    public function index()
    {
        return view('admin.settings.index', ['tiles' => [
            ['Страховые', Insurer::count(), ['страховая', 'страховые', 'страховых'], '/nastroyki/strahovye'],
            ['Ящики', Account::where('scope', Scope::Offers)->count(), ['ящик', 'ящика', 'ящиков'], '/nastroyki/yashchiki'],
            ['Шаблоны', Template::where('scope', Scope::Offers)->count(), ['шаблон писем', 'шаблона писем', 'шаблонов писем'], '/nastroyki/shablony'],
        ]]);
    }
}
