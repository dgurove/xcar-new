<?php

namespace App\Http\Admin;

use App\Mail\LetterPrompt;
use App\Offers\Actions\Disclose;
use App\Offers\Deal;
use App\Offers\Disclosures;
use App\Offers\ReplyReview;
use App\Offers\SupplierInvoice;
use App\Workflow\Outcome;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Разобрать ответ» в сделке CRM (`x-deal.reply-review`, 03.11.2026): показать менеджеру отмеченное из писем страховой или
 * «Ничего не показывать». Пункты сверяются с пересчитанными (`Disclosures::picked`): чужой документ не пройдёт.
 */
class DisclosureController
{
    public function store(Request $request, Deal $deal, Disclose $disclose)
    {
        abort_unless($deal->buyer_id && $deal->isActive(), 404);
        $data = $request->validate([
            'on' => ['array'], 'on.*' => ['string', 'max:80'],
            'value' => ['array'], 'value.*' => ['nullable', 'string', 'max:300'],
            'text' => ['nullable', 'string', 'max:2000'],
            'step' => ['nullable', 'integer'],
        ]);
        $letters = ReplyReview::pending()->where('deal_id', $deal->id)->with('message')->get()->pluck('message')->filter()->values();
        $pick = collect($data['on'] ?? [])->mapWithKeys(fn ($key) => [$key => $data['value'][$key] ?? null])->all();
        $items = Disclosures::picked(Disclosures::proposals($deal, $letters), $pick, $data['text'] ?? null);
        $step = isset($data['step']) ? Outcome::find($data['step']) : null;
        ['moved' => $moved] = $disclose($deal, $request->user(), $items, $step);
        // Шаг ушёл на этап с нашим письмом («Договор готов» → «Свободное время для подписания», 11.11.2026) — сразу вопрос про
        // письмо (`LetterPrompt`), как после «Принять»: черновик уже с днями покупателя.
        $offer = $deal->offer->fresh();
        if ($moved && $offer->position()?->letterStatus() === 'unsent' && ($url = $offer->vendorLetterUrl())) {
            LetterPrompt::ask($request->user(), $offer, $url.'&then=close');
        }

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', match (true) {
            $items !== [] => $moved ? 'Показано менеджеру, шаг пройден' : 'Показано менеджеру',
            $moved => 'Шаг пройден',
            default => 'Ответ разобран',
        });
    }

    /**
     * «Счёт поставщика» (`x-deal.supplier-invoice-sheet`, 10.11.2026): выбранный документ или новый файл — менеджеру счётом
     * (`SupplierInvoice::mark`), своё сообщение, и шаг «Счёт от поставщика получен», если нажали его.
     */
    public function invoice(Request $request, Deal $deal, Disclose $disclose)
    {
        abort_unless($deal->buyer_id && $deal->isActive(), 404);
        $data = $request->validate([
            'media' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:20480'],
            'text' => ['nullable', 'string', 'max:2000'],
            'exit' => ['nullable', 'integer'],
        ]);
        $offer = $deal->offer;
        $exit = isset($data['exit']) ? Outcome::find($data['exit']) : null;
        abort_if($exit && ! SupplierInvoice::needs($deal, $exit), 404);
        if ($file = $request->file('file')) {
            $name = $file->getClientOriginalName();
            $media = $offer->addMedia($file)->usingFileName(OfferPhotoController::safeName($name))->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('papers');
        } else {
            $media = SupplierInvoice::candidates($offer)->firstWhere('id', (int) ($data['media'] ?? 0));
        }
        if (! $media) {
            throw ValidationException::withMessages(['invoice' => 'Выберите счёт или добавьте файл']);
        }
        $items = [['kind' => 'file', 'value' => $media->name, 'media' => $media->id]];
        if ($text = trim((string) ($data['text'] ?? ''))) {
            $items[] = ['kind' => 'text', 'value' => mb_substr($text, 0, 2000)];
        }
        ['moved' => $moved] = $disclose($deal, $request->user(), $items, $exit, $media->id);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', $moved ? 'Счёт показан менеджеру, шаг пройден' : 'Счёт показан менеджеру');
    }

    public function dismiss(Request $request, Deal $deal, Disclose $disclose)
    {
        $disclose->dismiss($deal, $request->user());

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Менеджеру ничего не показано');
    }
}
