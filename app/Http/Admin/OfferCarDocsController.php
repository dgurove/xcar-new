<?php

namespace App\Http\Admin;

use App\Mail\Extraction\Patterns;
use App\Offers\Actions\SettleContract;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use Illuminate\Http\Request;

/** «ПТС и СТС» редактора (19.10.2026): документы ТС — в машину через `UpdateOffer`, с историей. */
class OfferCarDocsController
{
    public function update(Request $request, Offer $offer, UpdateOffer $update)
    {
        $data = $request->validate(['plate' => ['nullable', 'string', 'max:12'], 'pts' => ['nullable', 'string', 'max:40'], 'sts' => ['nullable', 'string', 'max:40'],
            'body_no' => ['nullable', 'string', 'max:40'], 'chassis_no' => ['nullable', 'string', 'max:40']],
            [], ['plate' => 'госномер', 'pts' => 'ПТС', 'sts' => 'СТС', 'body_no' => 'номер кузова', 'chassis_no' => 'номер шасси']);
        $data = array_map(fn ($v) => $v === null ? null : mb_strtoupper(trim($v)), $data);
        if (isset($data['plate'])) {
            $data['plate'] = Patterns::plateKey($data['plate']);
        }
        $update($offer, $data, $request->user(), ['why' => 'ПТС и СТС']);
        // Договор ждал документы ТС — собрался: номер, дата, менеджеру «ДКП готов».
        if ($contract = $offer->deal()->first()?->contract) {
            app(SettleContract::class)($contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']));
        }

        return back()->with('toast', 'ПТС и СТС сохранены');
    }
}
