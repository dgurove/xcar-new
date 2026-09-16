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
            ['Страховые', Insurer::count(), '/settings/insurers'],
            ['Ящики', Account::where('scope', Scope::Offers)->count(), '/settings/mailboxes'],
            ['Шаблоны', Template::where('scope', Scope::Offers)->count(), '/settings/templates'],
            ['Метки', Tag::count(), '/settings/tags'],
        ];
        if ($request->user()->isAdmin()) {
            $tiles[] = ['Пользователи', User::whereIn('role', [Role::Admin, Role::Moderator, Role::Manager])->count(), '/settings/users'];
            $tiles[] = ['Покупатели', User::where('role', Role::Buyer)->count(), '/settings/users?preset=buyers'];
        }

        return view('admin.settings.index', ['tiles' => $tiles]);
    }
}
