<?php

namespace App\Offers\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Черновик удалён так, будто его не было (`Offer::isDeletableBy` решает, можно ли): письма отвязываются, цепочка «Из
 * писем», из которой его завели, снова ждёт. Машина (`Cars\Car`) с фото и документами уходит с ним, если другой дорожки у
 * неё нет; ТС парковки той же машины — остаётся с ними (владелец 08.10.2026), в её истории «Снята с продажи».
 */
final class DeleteDraft
{
    public function __invoke(Offer $offer, User $by): void
    {
        DB::transaction(function () use ($offer) {
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            $ids = Candidate::where('offer_id', $offer->id)->where('state', CandidateState::Promoted)->pluck('id');
            Candidate::whereIn('id', $ids)->update(['state' => CandidateState::New, 'offer_id' => null]);
            // Удалили черновик, что завела почта, — цепочка ждёт человека, сама больше не заводится.
            $ids->each(fn ($id) => AutoOffer::declined($id));
            $offer->delete();
        });
        Nav::forgetStaffCounts();
    }
}
