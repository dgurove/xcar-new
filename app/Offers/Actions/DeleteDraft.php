<?php

namespace App\Offers\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Park\Actions\UnwindVehicle;
use App\Park\Vehicle;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Черновик удалён так, будто его не было (`Offer::isDeletableBy` решает, можно ли): заведённый вместе с ним вывоз
 * откатывается (`UnwindVehicle`), письма отвязываются, цепочка «Из писем», из которой его завели, снова ждёт; фото и
 * документы черновика уходят с ним (свои — `ownMedia`, ТС парковки свои оставляет у себя).
 */
final class DeleteDraft
{
    public function __invoke(Offer $offer, User $by): void
    {
        DB::transaction(function () use ($offer, $by) {
            foreach (Vehicle::where('offer_id', $offer->id)->where('created_at', '>=', $offer->created_at)->get() as $vehicle) {
                if (UnwindVehicle::allowed($vehicle)) {
                    app(UnwindVehicle::class)($vehicle, $by);
                }
            }
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            Candidate::where('offer_id', $offer->id)->where('state', CandidateState::Promoted)->update(['state' => CandidateState::New, 'offer_id' => null]);
            $offer->delete();
        });
        Nav::forgetStaffCounts();
    }
}
