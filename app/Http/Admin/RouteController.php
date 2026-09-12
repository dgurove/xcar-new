<?php

namespace App\Http\Admin;

use App\Offers\Offer;
use App\Workflow\Actions\DropRoute;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;

/** Маршрут на карточке оффера: наши исходы и ручная постановка на этап. */
class RouteController
{
    public function exit(Request $request, Offer $offer, Outcome $exit, TakeExit $take)
    {
        $payload = [];
        foreach ($exit->to?->staff_fields ?? [] as $field) {
            $value = trim((string) $request->input("fields.{$field['key']}", ''));
            if ($value !== '') {
                $payload[$field['key']] = $value;
            }
        }
        $take($offer, $exit, Actor::Staff, $request->user(), $payload);

        return redirect("/predlozheniya/{$offer->number}")->with('toast', $exit->label);
    }

    public function place(Request $request, Offer $offer, PlaceOnStage $place)
    {
        $stage = Stage::findOrFail($request->validate(['stage_id' => ['required', 'integer']])['stage_id']);
        abort_unless($stage->workflow->insurer_id === $offer->insurer_id, 403);
        $place($offer, $stage, $request->user());

        return redirect("/predlozheniya/{$offer->number}")->with('toast', 'Поставлен на «'.$stage->name.'»');
    }

    /** Вывоз по решению сотрудника — там, где он исключение, а не правило. */
    public function pickup(Request $request, Offer $offer, StartRoute $start)
    {
        $start($offer->load('insurer.workflows'), $request->user(), Track::Service);

        return redirect("/predlozheniya/{$offer->number}")->with('toast', 'Вывоз запущен');
    }

    public function dropPickup(Request $request, Offer $offer, DropRoute $drop)
    {
        $drop($offer, Track::Service, $request->user());

        return redirect("/predlozheniya/{$offer->number}")->with('toast', 'Вывоз отменён');
    }
}
