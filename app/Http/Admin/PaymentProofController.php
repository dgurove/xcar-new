<?php

namespace App\Http\Admin;

use App\Offers\Deal;
use App\Offers\Documents\PaymentProofPdf;
use App\Workflow\Actions\AttachRequirementFiles;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Outcome;
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
        abort_unless($request->user()->canManageCrm(), 403);
        $requirement = $this->open($deal);
        $request->validate(['files' => ['array'], 'files.*' => ['file', 'max:30720']]);
        foreach ($request->file('files', []) as $file) {
            $attach($requirement, $file);
        }
        if ($requirement->refresh()->getMedia('files')->isEmpty()) {
            throw ValidationException::withMessages(['payment_proof' => 'Добавьте чек, скан или файл платёжки']);
        }
        if (! $request->boolean('pass')) {
            return back()->with('toast', 'Документ приложен');
        }
        $exit = $requirement->stage->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $x) => in_array($x->label, PaymentProofPdf::EXITS, true));
        if (! $exit) {
            throw ValidationException::withMessages(['payment_proof' => 'У шага нет ответа «Оплатил, документ приложен»']);
        }
        Requirement::closeOpen($deal, $exit->stage_id, ['exit' => $exit->label, 'closed_by' => 'staff', 'by' => $request->user()->id]);
        $take($deal->offer, $exit, Actor::Manager, $request->user(), ['for' => 'manager']);

        return back()->with('toast', 'Оплата приложена, шаг пройден');
    }

    public function remove(Request $request, Deal $deal, Media $media)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $requirement = $this->open($deal);
        abort_unless($media->model_type === $requirement->getMorphClass() && $media->model_id === $requirement->id, 404);
        $media->delete();

        return back()->with('toast', 'Файл убран');
    }

    /** «Документ об оплате, PDF» — посмотреть или скачать. */
    public function pdf(Request $request, Deal $deal)
    {
        abort_unless($request->user()->canManageCrm(), 403);
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
