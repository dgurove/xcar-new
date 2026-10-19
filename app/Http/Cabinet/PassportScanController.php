<?php

namespace App\Http\Cabinet;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Live\RefreshDeal;
use App\Media\PhotoIngest;
use App\Offers\Actions\SettleContract;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

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
        // Скан одним PDF (19.10.2026, владелец: «иногда не фото, а PDF с двумя страницами — разворот и прописка»): первая
        // страница — разворот с фото, вторая — прописка, на какую плитку ни бросили. Картинками, как фото с телефона:
        // миниатюры и чтение те же.
        $pages = str_starts_with((string) $file->getMimeType(), 'image/') ? [] : self::pdfPages($file->getRealPath());
        if (count($pages) >= 2) {
            foreach (array_keys(Party::PASSPORT_PAGES) as $i => $p) {
                $this->store($party, $p, fn ($props) => $ingest->fromString($party, 'passport', $pages[$i], 'passport-'.$p.'.jpg', $props, self::MAX), $me);
            }
        } else {
            $this->store($party, $page, fn ($props) => str_starts_with((string) $file->getMimeType(), 'image/')
                ? $ingest->fromUpload($party, 'passport', $file, $props, self::MAX)
                : $party->addMedia($file)->usingFileName('passport-'.$page.'.pdf')
                    ->withCustomProperties($props + ['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('passport'), $me);
        }
        $this->resync($party, $me, $invoices);

        return response()->json($this->state($party));
    }

    /** Новая страница скана вместо прежней и её чтение. @param  callable(array): \Spatie\MediaLibrary\MediaCollections\Models\Media  $add */
    private function store(Party $party, string $page, callable $add, User $me): void
    {
        $old = $party->passportScan($page);
        $media = $add(['page' => $page, 'read' => 'reading', 'by' => $me->id]);
        $old?->delete();
        $party->unsetRelation('media');
        ReadPassport::dispatch($media->id);
    }

    /** Первые две страницы PDF картинками JPEG (200 dpi — строка паспорта мелкая); одна страница или не PDF — пусто. @return list<string> */
    private static function pdfPages(string $path): array
    {
        $dir = sys_get_temp_dir().'/passport-'.bin2hex(random_bytes(6));
        @mkdir($dir);
        try {
            Process::timeout(60)->run(['pdftoppm', '-jpeg', '-r', '200', '-f', '1', '-l', '2', $path, $dir.'/p']);
            $files = glob($dir.'/p-*.jpg') ?: [];
            sort($files, SORT_NATURAL);

            return array_map('file_get_contents', $files);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
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
            } else {
                RefreshDeal::of($deal);
            }
        }
    }
}
