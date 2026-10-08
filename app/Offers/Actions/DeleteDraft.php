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
 * писем», из которой его завели, снова ждёт; свои фото и документы уходят с ним (`ownMedia`). ТС парковки, связанная с
 * ним, — та же машина: она и её файлы остаются, связь снимает `Sale::end` (владелец 08.10.2026).
 */
final class DeleteDraft
{
    public function __invoke(Offer $offer, User $by): void
    {
        DB::transaction(function () use ($offer) {
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            Candidate::where('offer_id', $offer->id)->where('state', CandidateState::Promoted)->update(['state' => CandidateState::New, 'offer_id' => null]);
            $offer->delete();
        });
        Nav::forgetStaffCounts();
    }
}
