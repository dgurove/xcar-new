<?php

namespace App\Http\Park;

use App\Mail\Actions\MarkThreadRead;
use App\Mail\BodyRenderer;
use App\Mail\Scope;
use App\Mail\Thread;
use Illuminate\Http\Request;

/** Почта стоянки — та же почта, свой ящик и свой адрес. Страницы ветки нет: письма читаются в окне. */
class MailController extends \App\Http\Admin\MailController
{
    public function __construct()
    {
        parent::__construct(Scope::Park, '/mail', '/requests/from-mail');
    }

    /** Ссылка на ветку (уведомление, закладка) — список с открытым окном этой ветки. */
    public function show(Request $request, Thread $thread, MarkThreadRead $markRead, BodyRenderer $renderer)
    {
        return redirect('/mail?window='.$thread->id);
    }
}
