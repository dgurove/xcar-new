<?php

namespace App\Http\Logistics;

use App\Logistics\Client;
use App\Search\Term;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Заказчики логиста (18.10.2026, владелец: «удобно заводить заказчиков»): строками — имя, телефон, сколько заявок и долг;
 * нажатие — шторка с полями, «Новая заявка» и «Заявки N». Только свои (`logist_id`). Поле «Заказчик» формы заявки ищет
 * здесь же (`/reference/clients`) и заводит нового по имени.
 */
class ClientController
{
    public function index(Request $request)
    {
        $me = $this->me($request);
        $archive = $request->query('preset') === 'archive';
        $clients = Client::of($me)->when($archive, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->withCount('transports')
            ->withSum(['transports as owed' => fn ($q) => $q->whereRaw(Client::owedSql())], 'client_price')
            ->orderBy('name')->get();

        return view('logistics.clients.index', [
            'clients' => $clients, 'archive' => $archive,
            'archived' => Client::of($me)->whereNotNull('archived_at')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);
        $client = Client::create(['logist_id' => $me->id] + $this->data($request));

        return redirect('/clients')->with('toast', $client->name.': заказчик добавлен');
    }

    public function update(Request $request, Client $client)
    {
        $this->own($request, $client);
        $client->update($this->data($request));

        return back()->with('toast', 'Сохранено');
    }

    /** В архив — не нужен в списке и в поле заявки; заявки и долги остаются. Повторно — вернуть. */
    public function archive(Request $request, Client $client)
    {
        $this->own($request, $client);
        $client->update(['archived_at' => $client->archived_at ? null : now()]);

        return redirect('/clients')->with('toast', $client->archived_at ? $client->name.': в архиве' : $client->name.': снова в списке');
    }

    /** Поле «Заказчик» формы заявки: свои, не в архиве, по куску имени, контакта или телефона. */
    public function search(Request $request)
    {
        $me = $this->me($request);
        $t = Term::of((string) $request->query('q', ''));
        $clients = Client::of($me)->whereNull('archived_at')
            ->when(! $t->blank(), fn ($w) => $w->where(fn ($o) => $o->where('name', 'ilike', Term::like($t->raw))
                ->orWhere('contact_name', 'ilike', Term::like($t->raw))
                ->when($t->phone, fn ($o, $phone) => $o->orWhere('phone', 'like', '%'.$phone.'%'))))
            ->withCount('transports')->orderByDesc('transports_count')->orderBy('name')->limit(20)->get();

        return response()->json($clients->map(fn (Client $c) => ['id' => $c->id, 'label' => $c->name, 'hint' => $c->phone ? Phone::format($c->phone) : $c->contact_name]));
    }

    /** «Добавить «…»» в поле заявки — заказчик с одним именем, остальное потом в «Заказчиках». */
    public function quick(Request $request)
    {
        $me = $this->me($request);
        $name = trim($request->validate(['name' => ['required', 'string', 'max:200']])['name']);
        $client = Client::of($me)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Client::create(['logist_id' => $me->id, 'name' => $name]);

        return response()->json(['id' => $client->id, 'label' => $client->name]);
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in(array_keys(Client::KINDS))],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'inn' => ['nullable', 'regex:/^\d{10}(\d{2})?$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['name.required' => 'Название или имя', 'inn.regex' => 'ИНН — 10 или 12 цифр']);
        $data['phone'] = Phone::normalize($data['phone'] ?? null);

        return $data;
    }

    private function me(Request $request)
    {
        abort_unless($request->user()->isLogist(), 404);

        return $request->user();
    }

    private function own(Request $request, Client $client): void
    {
        abort_unless($client->logist_id === $this->me($request)->id, 404);
    }
}
