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
 */
class MailTemplateController
{
    public function index()
    {
        return view('admin.mail.templates', ['templates' => Template::when($this->park(), fn ($q) => $q->where('scope', Scope::Park))
            ->orderBy('scope')->orderBy('name')->get(), 'base' => $this->base()]);
    }

    public function create()
    {
        return $this->form(new Template(['scope' => $this->park() ? Scope::Park : Scope::Offers]));
    }

    public function edit(Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);

        return $this->form($template);
    }

    public function store(Request $request)
    {
        $template = Template::create($this->data($request));

        return redirect($this->base()."/{$template->id}")->with('toast', 'Шаблон сохранён');
    }

    public function update(Request $request, Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);
        $template->update($this->data($request));

        return redirect($this->base()."/{$template->id}")->with('toast', 'Сохранено');
    }

    public function destroy(Template $template)
    {
        abort_if($this->park() && $template->scope !== Scope::Park, 404);
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

    private function data(Request $request): array
    {
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
        // Смысл письма и вложения — только у ответов парковки.
        if (($data['scope'] instanceof Scope ? $data['scope'] : Scope::from($data['scope'])) !== Scope::Park) {
            $data['intent'] = $data['attach'] = null;
        }

        return $data;
    }
}
