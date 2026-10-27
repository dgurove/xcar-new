<?php

namespace App\Http\Site;

use App\Http\Middleware\MarkInstalled;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * `/` сайта и парковки. Отдельной главной до входа нет (27.10.2026, владелец: «надписи — кринж, нам не нужна реклама, у нас
 * закрытая система»; как у закрытых систем — банк-клиент, CRM): гость сразу на экране входа, вошедший и установленное
 * приложение — в свой список (Surface::home), старые установки с `/?app=1` — туда же.
 */
class LandingController
{
    public function site(Request $request)
    {
        return redirect($request->user() || MarkInstalled::installed($request) ? Surface::Site->home() : '/login');
    }

    public function park(Request $request)
    {
        return redirect($request->user() || MarkInstalled::installed($request) ? Surface::Park->home() : '/login');
    }
}
