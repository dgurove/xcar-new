<?php

namespace App\Http\Site;

use App\Http\Middleware\MarkInstalled;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * `/` сайта и парковки — первый экран для гостя: что это и как войти. Вошедший и установленное приложение
 * сюда не смотрят: сразу в свой список (Surface::home), старые установки с `/?app=1` — туда же.
 */
class LandingController
{
    public function site(Request $request)
    {
        if ($request->user() || MarkInstalled::installed($request)) {
            return redirect(Surface::Site->home());
        }

        return view('landing.site');
    }

    public function park(Request $request)
    {
        if ($request->user() || MarkInstalled::installed($request)) {
            return redirect(Surface::Park->home());
        }

        return view('landing.park');
    }
}
