<?php

namespace App\Http\Cabinet;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Media\PhotoIngest;
use App\Offers\Actions\SettleContract;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Скан паспорта покупателя (11.10.2026, владелец: «пусть прикрепляют скан, распознаётся, поля можно поправить; без
 * скана счёт и договор не сформировать»). Две страницы — разворот с фото (`main`) и прописка (`reg`); новая заменяет
 * прежнюю. Читает задача `ReadPassport` на нашем сервере, шторка опрашивает `live` и заполняет пустые поля.
 */
class PassportScanController
{
    /** Крупнее обычного фото (1600): строка паспорта мелкая, при сжатии не читается. */
    private const MAX = 2600;

    public function upload(Request $request, Party $party, string $page, PhotoIngest $ingest, SyncDealInvoices $invoices)
    {
        $me = $request->user();
        abort_unless(isset(Party::PASSPORT_PAGES[$page]) && ($me->isAdmin() || $party->managedBy($me)), 404);
        $request->validate(['file' => ['required', 'file', 'max:30720', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf']]);
        $file = $request->file('file');
        $props = ['page' => $page, 'read' => 'reading', 'by' => $me->id];
        $old = $party->passportScan($page);
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $media = $ingest->fromUpload($party, 'passport', $file, $props, self::MAX);
        } else {
            $media = $party->addMedia($file)->usingFileName('passport-'.$page.'.pdf')
                ->withCustomProperties($props + ['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('passport');
        }
        $old?->delete();
        ReadPassport::dispatch($media->id);
        $this->resync($party, $me, $invoices);

        return response()->json($this->state($party));
    }

    /** Что прочитано: по странице — состояние, миниатюра и поля; шторка заполняет пустые. */
    public function live(Request $request, Party $party)
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $party->managedBy($me), 404);

        return response()->json($this->state($party));
    }

    private function state(Party $party): array
    {
        $party->load('media');
        $pages = [];
        foreach (array_keys(Party::PASSPORT_PAGES) as $page) {
            $m = $party->passportScan($page);
            $pages[$page] = $m ? [
                'state' => $m->getCustomProperty('read', 'done'),
                'url' => '/files/'.$m->id,
                'thumb' => '/files/'.$m->id.'?thumb=1',
                'fields' => $m->getCustomProperty('fields', []),
                'checked' => $m->getCustomProperty('checked', []),
            ] : null;
        }

        return ['pages' => $pages, 'reading' => collect($pages)->contains(fn ($p) => ($p['state'] ?? null) === 'reading')];
    }

    /** Скан встал — у идущих сделок этого покупателя счёт ПРАЙМ и договор могут встать сами (`Party::readyAsBuyer`). */
    private function resync(Party $party, User $me, SyncDealInvoices $invoices): void
    {
        $user = User::where('party_id', $party->id)->first();
        if (! $user) {
            return;
        }
        self::resyncFor($user, $me, $invoices);
    }

    /** Сделки, где этот человек — покупатель по договору или сам менеджер-покупатель: пересобрать счёт и готовность ДКП. */
    public static function resyncFor(User $user, User $by, SyncDealInvoices $invoices): void
    {
        $deals = Deal::where('state', DealState::Active)->where(fn ($q) => $q->whereHas('contract', fn ($c) => $c->where('buyer_user_id', $user->id))->orWhere('buyer_id', $user->id))->get();
        foreach ($deals as $deal) {
            $invoices($deal->fresh(['offer', 'buyer', 'contract.buyer']), $by);
            if ($contract = $deal->contract?->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer'])) {
                app(SettleContract::class)($contract);
            }
        }
    }
}
