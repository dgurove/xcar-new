<?php

namespace App\Offers\Actions;

use App\Billing\Charge;
use App\Billing\Invoice;
use App\Chats\Chat;
use App\Chats\File as ChatFile;
use App\Garage\Car as GarageCar;
use App\Mail\Candidate;
use App\Mail\Thread;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Nav;
use App\Workflow\Requirement;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Предложение из архива — навсегда, со всем, что с ним связано (владелец 05.10.2026: «вычищать всё, чтобы фотки не
 * занимали место»): кадры и документы (свои и принесённые им на ТС парковки), сделки, подтверждения, интерес, показы,
 * чаты с файлами, ответы менеджера с файлами, машина гаража с расходами, счета и оплаты по нему с PDF и платёжками
 * (решение владельца — и счета тоже), строки ленты по нему. Остаются: ТС парковки со своими кадрами (её дело — у
 * парковки), письма (ветки отвязываются, вложения отпустит `storage:gc`), цепочка «Из писем» (отвязывается).
 * Файлы удаляются моделями (spatie, `Chats\File::deleted`), а не каскадом базы: каскад оставил бы их на диске.
 */
final class PurgeOffer
{
    public function __invoke(Offer $offer): void
    {
        if (! in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true)) {
            throw ValidationException::withMessages(['offer' => 'Удалить навсегда можно только из архива']);
        }

        DB::transaction(function () use ($offer) {
            $deals = Deal::where('offer_id', $offer->id)->pluck('id');
            $garage = GarageCar::where('offer_id', $offer->id)->get();
            $invoices = Invoice::where('offer_id', $offer->id)
                ->orWhereIn('deal_id', $deals)
                ->orWhereIn('id', $garage->pluck('invoice_id')->merge($garage->pluck('payout_invoice_id'))->filter())
                ->get();
            $subjects = ['/offers/'.$offer->number, ...$deals->map(fn ($d) => '/deals/'.$d)];

            // Деньги: платёжки (media slip) и PDF счёта и акта уходят моделями, оплаты — вместе со счётом.
            foreach ($invoices as $invoice) {
                $invoice->payments()->get()->each->delete();
                $invoice->delete();
            }
            Charge::whereIn('deal_id', $deals)->get()->each->delete();
            $garage->each->delete();

            // Чаты по предложению: файлы сообщений — моделью (диск private), остальное каскадом.
            $chats = Chat::where('offer_id', $offer->id)->pluck('id');
            ChatFile::whereIn('message_id', DB::table('chat_messages')->whereIn('chat_id', $chats)->select('id'))->get()->each->delete();
            $subjects = [...$subjects, ...$chats->map(fn ($c) => '/account/chats/'.$c)];
            Chat::whereIn('id', $chats)->delete();

            // Ответы менеджера на этапах — с файлами.
            Requirement::where('offer_id', $offer->id)->get()->each->delete();

            // ТС парковки остаётся у парковки; кадры и документы, что принесло предложение, возвращаются ему (`Sale`) и
            // уходят вместе с ним ниже.
            if ($vehicle = $offer->parkVehicle) {
                $vehicle->update(['offer_id' => null]);
            }
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            Candidate::where('offer_id', $offer->id)->update(['offer_id' => null]);

            DatabaseNotification::whereIn('data->subject', $subjects)->delete();
            // Свои кадры и документы — spatie при удалении (`Offer::deleteAllMedia`); остальное — каскадом базы.
            $offer->delete();
        });
        Nav::forgetStaffCounts();
    }
}
