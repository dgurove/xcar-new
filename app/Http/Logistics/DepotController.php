<?php

namespace App\Http\Logistics;

use App\Cars\Settlement;
use App\Logistics\Depot;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Support\Phone;
use Illuminate\Http\Request;

/**
 * Стоянки логиста (18.10.2026, владелец: «вывезли в Кирове, поставили на стоянку — ждёт»): строками — название, город и
 * адрес, сколько машин стоит; шторка — машины на ней (строка ведёт в задачу), звонок, поля, «В архив». Только свои.
 * Заводят и прямо в окне «Исполнитель» (`x-people.pick` вид `depot`).
 */
class DepotController
{
    public function index(Request $request)
    {
        $me = $this->me($request);
        $archive = $request->query('preset') === 'archive';
        $depots = Depot::of($me)->when($archive, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->with('settlement')->withWaiting()->orderByDesc('waiting_count')->orderBy('name')->get();
        $standing = Transport::whereIn('from_depot_id', $depots->pluck('id'))->whereIn('state', [TransportState::New, TransportState::Logist, TransportState::Assigned, TransportState::Agreed])
            ->with(['car', 'executor'])->get()->groupBy('from_depot_id');

        return view('logistics.depots.index', ['depots' => $depots, 'standing' => $standing, 'archive' => $archive,
            'archived' => Depot::of($me)->whereNotNull('archived_at')->count()]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);
        $depot = Depot::create(['logist_id' => $me->id] + $this->data($request));

        return redirect('/depots')->with('toast', 'Стоянка '.$depot->name.' добавлена');
    }

    public function update(Request $request, Depot $depot)
    {
        $this->own($request, $depot);
        $depot->update($this->data($request));

        return back()->with('toast', 'Сохранено');
    }

    public function archive(Request $request, Depot $depot)
    {
        $this->own($request, $depot);
        $depot->update(['archived_at' => $depot->archived_at ? null : now()]);

        return redirect('/depots')->with('toast', $depot->archived_at ? 'Стоянка '.$depot->name.' в архиве' : 'Стоянка '.$depot->name.' снова в списке');
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['name.required' => 'Название']);
        $data['settlement_id'] = ($data['city'] ?? null) ? (Settlement::named($data['city'])['id'] ?? null) : null;
        $data['phone'] = Phone::normalize($data['phone'] ?? null);
        unset($data['city']);

        return $data;
    }

    private function me(Request $request)
    {
        abort_unless($request->user()->isLogist(), 404);

        return $request->user();
    }

    private function own(Request $request, Depot $depot): void
    {
        abort_unless($depot->logist_id === $this->me($request)->id, 404);
    }
}
