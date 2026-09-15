<?php

namespace App\Http\Admin;

use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\Tag;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Insurer;
use Illuminate\Http\Request;

/** Хаб настроек: справочное, что меняется редко, — плитками со счётчиками. */
class SettingsController
{
    public function index(Request $request)
    {
        $tiles = [
            ['Страховые', Insurer::count(), '/nastroyki/strahovye'],
            ['Ящики', Account::where('scope', Scope::Offers)->count(), '/nastroyki/yashchiki'],
            ['Шаблоны', Template::where('scope', Scope::Offers)->count(), '/nastroyki/shablony'],
            ['Метки', Tag::count(), '/nastroyki/tegi'],
        ];
        if ($request->user()->isAdmin()) {
            $tiles[] = ['Пользователи', User::whereIn('role', [Role::Admin, Role::Moderator, Role::Manager])->count(), '/nastroyki/polzovateli'];
            $tiles[] = ['Покупатели', User::where('role', Role::Buyer)->count(), '/nastroyki/polzovateli?preset=buyers'];
        }

        return view('admin.settings.index', ['tiles' => $tiles]);
    }
}
