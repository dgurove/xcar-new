<?php

namespace App\Http\Site;

use App\Offers\Actions\RegisterInterest;
use App\Offers\Offer;
use Illuminate\Http\Request;

class InterestController
{
    public function store(Request $request, Offer $offer, RegisterInterest $register)
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $register($offer, $request->user(), $data['comment'] ?? null);

        return back()->with('toast', 'Менеджер свяжется с вами');
    }
}
