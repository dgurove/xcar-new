<?php

namespace App\Http\Admin;

use App\Mail\Scope;
use App\Mail\Template;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailTemplateController
{
    public function index()
    {
        return view('admin.mail.templates', ['templates' => Template::orderBy('scope')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.mail.template', ['template' => new Template(['scope' => Scope::Offers])]);
    }

    public function edit(Template $template)
    {
        return view('admin.mail.template', ['template' => $template]);
    }

    public function store(Request $request)
    {
        $template = Template::create($this->data($request));

        return redirect("/admin/shablony/{$template->id}")->with('toast', 'Шаблон сохранён');
    }

    public function update(Request $request, Template $template)
    {
        $template->update($this->data($request));

        return redirect("/admin/shablony/{$template->id}")->with('toast', 'Сохранено');
    }

    public function destroy(Template $template)
    {
        $template->delete();

        return redirect('/admin/shablony')->with('toast', 'Удалён');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scope' => ['required', Rule::enum(Scope::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:50000'],
        ]);
    }
}
