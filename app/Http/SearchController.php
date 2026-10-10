<?php

namespace App\Http;

use App\Search\Search;
use App\Search\Term;
use App\Support\Surface;
use Illuminate\Http\Request;

/**
 * Поиск в шапке — один на три хоста (`Search\Search::for` по поверхности). Во фрейм выпадающего списка — кусок
 * `search/results`; открыли адрес руками или обновили — та же выдача страницей.
 */
final class SearchController
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $groups = Search::for($request->user(), Term::of($q), Surface::current());

        return view($request->header('Turbo-Frame') ? 'search.frame' : 'search.page', ['q' => $q, 'groups' => $groups]);
    }
}
