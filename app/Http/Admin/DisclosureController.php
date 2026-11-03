<?php

namespace App\Http\Admin;

use App\Offers\Actions\Disclose;
use App\Offers\Deal;
use App\Offers\Disclosures;
use App\Offers\ReplyReview;
use App\Workflow\Outcome;
use Illuminate\Http\Request;

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
        $disclose($deal, $request->user(), $items, $step);

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', $items ? 'Показано менеджеру' : ($step ? 'Шаг пройден' : 'Ответ разобран'));
    }

    public function dismiss(Request $request, Deal $deal, Disclose $disclose)
    {
        $disclose->dismiss($deal, $request->user());

        return back(fallback: '/work/deals/'.$deal->id)->with('toast', 'Менеджеру ничего не показано');
    }
}
