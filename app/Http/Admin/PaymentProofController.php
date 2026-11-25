<?php

namespace App\Http\Admin;

use App\Billing\Actions\SwapPaymentProof;
use App\Chats\Message;
use App\Media\PhotosPdf;
use App\Offers\Deal;
use App\Offers\Documents\PaymentProofPdf;
use App\Workflow\Actions\AttachChatFiles;
use App\Workflow\Actions\AttachRequirementFiles;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документ об оплате за менеджера (10.11.2026, владелец: «чтобы админы за него могли прикрепить, если он как-то по-другому
 * админам отправил»): файлы — в открытую просьбу шага оплаты, как у него на сайте (`AttachRequirementFiles`), и сразу его
 * ответ «Оплатил, документ приложен» (в «Истории» — за менеджера). PDF для страховой — `PaymentProofPdf`.
 */
final class PaymentProofController
{
    public function store(Request $request, Deal $deal, AttachRequirementFiles $attach, TakeExit $take)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $requirement = $this->open($deal);
        $request->validate(['files' => ['array'], 'files.*' => ['file', 'max:30720'], 'message' => ['nullable', 'integer']]);
        foreach ($request->file('files', []) as $file) {
            $attach($requirement, $file);
        }
        // Чек прислал в чат сделки (10.11.2026, `x-chat.doc-pick`): файлы его сообщения — без скачивания и новой загрузки.
        if ($request->filled('message')) {
            $message = Message::with(['files', 'chat'])->findOrFail((int) $request->input('message'));
            abort_unless($message->chat->offer_id === $deal->offer_id && $message->chat->user_id === $deal->buyer_id && ! $message->isDeleted(), 404);
            app(AttachChatFiles::class)($requirement, $message);
        }
        if ($requirement->refresh()->getMedia('files')->isEmpty()) {
            throw ValidationException::withMessages(['payment_proof' => 'Добавьте чек, скан или файл документа об оплате']);
        }
        if (! $request->boolean('pass')) {
            return back()->with('toast', 'Документ приложен');
        }
        $this->pass($deal, $requirement, $request, $take);

        return back()->with('toast', 'Документ об оплате приложен');
    }

    /**
     * «Приложить» за менеджера одним документом (24.11.2026, `x-ui.photo-pdf`): фото и файлы — один под названием человека
     * («Платёжное поручение Renault Duster»), и, как у `store` с `pass`, его ответ шага. Страница перечитывается.
     */
    public function storeDoc(Request $request, Deal $deal, AttachRequirementFiles $attach, TakeExit $take, PhotosPdf $photos)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $requirement = $this->open($deal);
        if ($request->boolean('pass') && ($gap = $deal->signingCityGap($requirement->stage))) {
            return response()->json(['message' => $gap], 422);
        }
        $doc = $photos->fromRequest($request, $deal->offer->title());
        $attach($requirement, $doc['file'], named: true);
        if ($request->boolean('pass') && ! $requirement->done_at) {
            $this->pass($deal, $requirement, $request, $take);
        }
        session()->flash('toast', 'Документ об оплате приложен');

        return response()->json(['reload' => true, 'name' => $doc['name'], 'pages' => $doc['pages']]);
    }

    /** Ответ менеджера «Оплатил, документ приложен» — за него (в «Истории» — за менеджера). */
    private function pass(Deal $deal, Requirement $requirement, Request $request, TakeExit $take): void
    {
        // За менеджера — тоже с городом подписания (25.11.2026): без него не пройти.
        if ($gap = $deal->signingCityGap($requirement->stage)) {
            throw ValidationException::withMessages(['payment_proof' => $gap]);
        }
        $exit = $deal->proofMove($requirement->stage);
        if (! $exit) {
            throw ValidationException::withMessages(['payment_proof' => 'У шага нет ответа «Оплатил, документ приложен»']);
        }
        Requirement::closeOpen($deal, $exit->stage_id, ['exit' => $exit->label, 'closed_by' => 'staff', 'by' => $request->user()->id]);
        $take($deal->offer, $exit, Actor::Manager, $request->user(), ['for' => 'manager']);
    }

    /** «Это оплата ООО «ПРАЙМ»»: платёжка поставщику — на самом деле чек нашего счёта (`SwapPaymentProof`, 16.11.2026). */
    public function toInvoice(Request $request, Deal $deal, SwapPaymentProof $swap)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $swap->toInvoice($deal, $request->user());

        return back()->with('toast', 'Чек перенесли в оплату по счёту, шаг снова «Оплата»');
    }

    public function remove(Request $request, Deal $deal, Media $media)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $requirement = $this->open($deal);
        abort_unless($media->model_type === $requirement->getMorphClass() && $media->model_id === $requirement->id, 404);
        $media->delete();

        return back()->with('toast', 'Файл убран');
    }

    /** «Документ об оплате, PDF» — посмотреть или скачать. */
    public function pdf(Request $request, Deal $deal)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $pdf = PaymentProofPdf::render($deal) ?? abort(404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename*=UTF-8\'\''.rawurlencode(PaymentProofPdf::fileName($deal))]);
    }

    /** Открытая просьба шага оплаты — туда менеджер прикладывает сам. */
    private function open(Deal $deal): Requirement
    {
        $requirement = $deal->openRequirement;
        $stage = $deal->offer?->position(Track::Sale)?->stage;
        abort_unless($requirement && $requirement->asks === Asks::Document && $stage?->isPayStep() && $requirement->stage_id === $stage->id, 404);

        return $requirement;
    }
}
