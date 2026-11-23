<?php

namespace App\Http\Admin;

use App\Mail\Extraction\Intent;
use App\Mail\Scope;
use App\Mail\Template;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Шаблоны писем: в CRM — все («Настройки → Шаблоны»), на парковке — только её (02.11.2026: управляющий правит свои
 * готовые ответы сам, в CRM ему нельзя). Шаблону парковки — на какое письмо предлагать первым и что приложить само.
 *
 * Доп. запросы поставщику (17.11.2026, владелец: «шаблоны пусть удобно добавляются и редактируются в кабинете в шаблонах»,
 * `Offers\ExtraRequest`) — своей группой первыми: галка, фраза после «Просьба …», варианты `{{ city }}`, «Приложить платёжку»;
 * порядок — перетаскиванием (`POST /settings/templates/order`), он же порядок фраз письма.
 */
class MailTemplateController
{
    public function index()
    {
        return view('admin.mail.templates', ['templates' => Template::when($this->park(), fn ($q) => $q->where('scope', Scope::Park))
            ->letters()->orderBy('scope')->orderBy('name')->get(), 'requests' => $this->park() ? collect() : Template::requests()->get(), 'base' => $this->base()]);
    }

    public function create(Request $request)
    {
        $ask = ! $this->park() && $request->query('kind') === Template::REQUEST;

        return $this->form(new Template(['scope' => $this->park() ? Scope::Park : Scope::Offers, 'kind' => $ask ? Template::REQUEST : Template::LETTER]));
    }

    /** Порядок доп. запросов (= порядок фраз письма) — перетаскиванием в списке. */
    public function order(Request $request)
    {
        abort_if($this->park(), 404);
        $ids = array_values(array_unique(array_map('intval', (array) $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']])['ids'])));
        $known = Template::requests()->pluck('id')->all();
        foreach (array_values(array_intersect($ids, $known)) as $i => $id) {
            Template::whereKey($id)->update(['position' => $i + 1]);
        }

        return response()->noContent();
    }

    public function edit(Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);

        return $this->form($template);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $this->checkFiles($request);
        if (($data['kind'] ?? null) === Template::REQUEST) {
            $data['position'] = (int) Template::requests()->max('position') + 1;
        }
        $template = Template::create($data);
        $this->files($request, $template);

        return redirect($this->base()."/{$template->id}")->with('toast', 'Шаблон сохранён');
    }

    public function update(Request $request, Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);
        // Письмо шага — только текст: по названию его находит `Template::park`.
        $data = $template->isStep() ? array_diff_key($this->data($request, $template), array_flip(['name', 'scope', 'intent', 'attach'])) : $this->data($request, $template);
        $this->checkFiles($request);
        $template->update($data);
        $this->files($request, $template);

        return redirect($this->base()."/{$template->id}")->with('toast', 'Сохранено');
    }

    /** Файлы проверяются до записи шаблона: иначе ошибка оставила бы шаблон сохранённым наполовину (новый — дублем). */
    private function checkFiles(Request $request): void
    {
        $request->validate(['files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480'],
            'drop_files' => ['nullable', 'array'], 'drop_files.*' => ['integer']], [], ['files.*' => 'Файл']);
    }

    /** Постоянные файлы письма CRM (доверенность к письму о вывозе, 23.11.2026): добавить новые, снять отмеченные. */
    private function files(Request $request, Template $template): void
    {
        if ($this->park() || $template->isRequest()) {
            return;
        }
        foreach ((array) $request->input('drop_files', []) as $id) {
            $template->media()->where('collection_name', 'files')->whereKey((int) $id)->first()?->delete();
        }
        foreach ((array) $request->file('files', []) as $file) {
            $template->addMedia($file)->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(['title' => $file->getClientOriginalName()])->toMediaCollection('files');
        }
    }

    public function destroy(Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);
        abort_if($template->isStep() || in_array($template->attach, Template::ROUTE_ATTACH, true), 403);
        $template->delete();

        return redirect($this->base())->with('toast', 'Удалён');
    }

    private function form(Template $template)
    {
        return view('admin.mail.template', ['template' => $template, 'base' => $this->base(), 'park' => $this->park(),
            'intents' => ['' => 'Никакие', ...collect(Intent::cases())->filter(fn (Intent $i) => $i->short() && ! in_array($i, [Intent::Auto, Intent::Bounce, Intent::Billing], true))
                ->mapWithKeys(fn (Intent $i) => [$i->value => $i->title()])->all()],
            'attaches' => ['' => 'Ничего', ...Template::ATTACH]]);
    }

    private function park(): bool
    {
        return Surface::current() === Surface::Park;
    }

    private function base(): string
    {
        return $this->park() ? '/templates' : '/settings/templates';
    }

    private function data(Request $request, ?Template $template = null): array
    {
        // Доп. запрос — галка, фраза, варианты города и платёжка; темы, смысла письма и стороны у него нет.
        if (! $this->park() && ($template?->isRequest() ?? $request->input('kind') === Template::REQUEST)) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:80'],
                'body' => ['required', 'string', 'max:300'],
                'choices' => ['nullable', 'string', 'max:2000'],
                'attach' => ['nullable', Rule::in([Template::PAY_PROOF])],
            ], [], ['name' => 'Галка', 'body' => 'Фраза']);
            $choices = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), preg_split('/\R/u', (string) ($data['choices'] ?? '')) ?: []))));

            return ['name' => $data['name'], 'body' => trim((string) preg_replace('/\s+/u', ' ', $data['body'])), 'choices' => $choices ? array_slice($choices, 0, 20) : null,
                'attach' => $data['attach'] ?? null, 'kind' => Template::REQUEST, 'scope' => Scope::Offers, 'subject' => null, 'intent' => null];
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scope' => [$this->park() ? 'nullable' : 'required', Rule::enum(Scope::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
            'intent' => ['nullable', Rule::enum(Intent::class)],
            'attach' => ['nullable', Rule::in(array_keys(Template::ATTACH))],
        ]);
        if ($this->park()) {
            $data['scope'] = Scope::Park;
        }
        // Смысл письма и вложения — только у ответов парковки. Вложение писем CRM (договор, акт вывоза) ставит маршрут — правка
        // текста его не снимает.
        if (($data['scope'] instanceof Scope ? $data['scope'] : Scope::from($data['scope'])) !== Scope::Park) {
            $data['intent'] = null;
            $data['attach'] = in_array($template?->attach, Template::ROUTE_ATTACH, true) ? $template->attach : null;
        }

        return $data;
    }
}
