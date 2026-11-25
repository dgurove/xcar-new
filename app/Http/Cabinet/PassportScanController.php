<?php

namespace App\Http\Cabinet;

use App\Billing\Actions\StorePassportScan;
use App\Billing\Documents\PassportPdf;
use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Live\RefreshDeal;
use App\Media\Actions\RotatePhoto;
use App\Media\Thumb;
use App\Offers\Actions\SettleContract;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Users\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Скан паспорта покупателя (11.10.2026, владелец: «пусть прикрепляют скан, распознаётся, поля можно поправить; без
 * скана счёт и договор не сформировать»). Две страницы — разворот с фото (`main`) и прописка (`reg`); новая заменяет
 * прежнюю (`StorePassportScan`). Читает задача `ReadPassport` на нашем сервере, шторка опрашивает `live` и заполняет поля.
 */
class PassportScanController
{
    public function upload(Request $request, Party $party, string $page, StorePassportScan $store, SyncDealInvoices $invoices)
    {
        $me = $request->user();
        abort_unless(isset(Party::PASSPORT_PAGES[$page]) && (self::staff($me, $party) || $party->managedBy($me)), 404);
        $request->validate(['file' => ['required', ...self::FILE]]);
        $store($party, $page, [$request->file('file')], $me);
        $this->resync($party, $me, $invoices);

        return response()->json($this->state($party));
    }

    /**
     * «Распознать заново» (13.11.2026, владелец: «скачивать и заново закидывать, чтобы распознавание запустилось — ебанистика»):
     * обе страницы читаются снова, прочитанное — поверх полей (`over`, `ReadPassport::apply`); шторка опрашивает `live`, как
     * после загрузки.
     */
    public function read(Request $request, Party $party)
    {
        $me = $request->user();
        abort_unless(self::staff($me, $party) || $party->managedBy($me), 404);
        $pages = $party->getMedia('passport');
        abort_if($pages->isEmpty(), 404);
        foreach ($pages as $media) {
            $media->setCustomProperty('turned', true)->setCustomProperty('read', 'reading')->setCustomProperty('over', true)->save();
            ReadPassport::dispatch($media->id);
        }

        return response()->json($this->state($party->fresh()));
    }

    /**
     * Повернуть страницу (16.11.2026, владелец: прописка осталась боком, «не хватает кнопок поворота»): файл на месте, нажатия
     * подряд — одним запросом (`turns` по часовой), и страница читается снова, прочитанное — поверх полей.
     */
    public function rotate(Request $request, Party $party, string $page, RotatePhoto $rotate)
    {
        $me = $request->user();
        abort_unless(self::staff($me, $party) || $party->managedBy($me), 404);
        $media = $party->passportScan($page);
        abort_unless($media && $media->getCustomProperty('read') !== 'reading', 404);
        $rotate($media, RotatePhoto::turns($request));
        Thumb::forget("thumb-m{$media->id}");
        // Новый отпечаток — новый текст: по прежнему кэш отдал бы прочитанное до поворота.
        $path = $media->refresh()->getPath();
        is_file($path) && $media->setCustomProperty('sha', hash_file('sha256', $path));
        $media->setCustomProperty('turned', true)->setCustomProperty('read', 'reading')->setCustomProperty('over', true)->save();
        ReadPassport::dispatch($media->id);

        return response()->json($this->state($party->fresh()));
    }

    /** Файл страницы скана: фото (и HEIC с айфона) или PDF. */
    public const FILE = ['file', 'max:30720', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'];

    /** Скан одним PDF — посмотреть и скачать (шторка документов, «Скачать»). */
    public function pdf(Request $request, Party $party)
    {
        $me = $request->user();
        abort_unless(self::staff($me, $party) || $party->managedBy($me), 404);
        $pdf = PassportPdf::render($party);
        abort_unless($pdf, 404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::makeDisposition('inline', PassportPdf::fileName($party), 'passport.pdf'), 'Cache-Control' => 'private, no-store']);
    }

    /** Что прочитано: по странице — состояние, миниатюра и поля; шторка заполняет пустые. */
    public function live(Request $request, Party $party)
    {
        $me = $request->user();
        abort_unless(self::staff($me, $party) || $party->managedBy($me), 404);

        return response()->json($this->state($party));
    }

    /**
     * Сотрудник CRM: админ — любой; модератор (14.11.2026, `canEditDeals`: данные ДКП) — только покупателя по договору сделки.
     */
    private static function staff(User $me, Party $party): bool
    {
        // И продавец ДКП собственника (25.11.2026: скан паспорта из письма страховой, `FillOwnerFromPassport`).
        return $me->isAdmin() || ($me->canEditDeals() && (DealContract::whereHas('buyer', fn ($q) => $q->where('party_id', $party->id))->exists()
            || DealContract::where('seller_party_id', $party->id)->exists()));
    }

    private function state(Party $party): array
    {
        $party->load('media');
        $pages = [];
        foreach (array_keys(Party::PASSPORT_PAGES) as $page) {
            $m = $party->passportScan($page);
            $pages[$page] = $m ? [
                'state' => $m->getCustomProperty('read', 'done'),
                'url' => '/files/'.$m->id.'?v='.$m->updated_at?->timestamp,
                'thumb' => '/files/'.$m->id.'?thumb=1&v='.$m->updated_at?->timestamp,
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
        $deals = Deal::live()->where(fn ($q) => $q->whereHas('contract', fn ($c) => $c->where('buyer_user_id', $user->id))->orWhere('buyer_id', $user->id))->get();
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
