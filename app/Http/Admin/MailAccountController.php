<?php

namespace App\Http\Admin;

use App\Mail\Account;
use App\Mail\Attachment;
use App\Mail\ConnectionTester;
use App\Mail\Jobs\SyncAccount;
use App\Mail\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MailAccountController
{
    public function index()
    {
        return view('admin.mail.accounts', ['accounts' => Account::withCount('messages')->orderBy('scope')->orderBy('title')->get()]);
    }

    public function create()
    {
        return view('admin.mail.account', ['replyOptions' => $this->replyOptions(), 'account' => new Account(['imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_port' => 2525, 'smtp_encryption' => 'tls', 'imap_host' => 'imap.mail.ru', 'smtp_host' => 'smtp.mail.ru', 'is_active' => true, 'scope' => Scope::Offers])]);
    }

    public function edit(Account $account)
    {
        return view('admin.mail.account', ['replyOptions' => $this->replyOptions($account), 'account' => $account, 'folders' => $account->folders()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $account = Account::create($this->data($request));

        return redirect("/settings/mailboxes/{$account->slug}")->with('toast', 'Ящик заведён');
    }

    public function update(Request $request, Account $account)
    {
        $account->update($this->data($request, $account));
        if ($request->has('folders')) {
            foreach ($account->folders as $folder) {
                $folder->update(['is_syncable' => in_array($folder->id, array_map('intval', (array) $request->input('folders')), true)]);
            }
        }

        return redirect("/settings/mailboxes/{$account->slug}")->with('toast', 'Сохранено');
    }

    public function test(Account $account, ConnectionTester $tester)
    {
        $imap = $tester->imap($account);
        $smtp = $tester->smtp($account);

        return back()->with('check', ['imap' => $imap, 'smtp' => $smtp]);
    }

    public function sync(Account $account)
    {
        SyncAccount::dispatch($account->id);

        return back()->with('toast', 'Синхронизация в очереди');
    }

    public function destroy(Account $account)
    {
        // Каскад в базе файлы не трогает: закреплённые вложения отпускаются через модель.
        Attachment::whereHas('message', fn ($q) => $q->where('account_id', $account->id))->whereNotNull('blob_sha')
            ->chunkById(200, fn ($chunk) => $chunk->each->delete());
        $account->delete();

        return redirect('/settings/mailboxes')->with('toast', 'Ящик удалён');
    }

    /** «Отвечаем с ящика»: другие ящики той же стороны, которые пишут сами. @return array<int, string> */
    private function replyOptions(?Account $account = null): array
    {
        return Account::whereNull('reply_account_id')->where('scope', $account?->scope ?? Scope::Offers)->when($account?->exists, fn ($q) => $q->where('id', '!=', $account->id))
            ->orderBy('title')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->email])->all();
    }

    private function data(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', Rule::unique('mail_accounts', 'email')->ignore($account?->id)],
            'from_name' => ['nullable', 'string', 'max:80'],
            'scope' => ['required', Rule::enum(Scope::class)],
            // Отвечаем с другого ящика той же стороны, который сам пишет (цепочек «принимает → принимает» нет).
            'reply_account_id' => ['nullable', 'integer', Rule::exists('mail_accounts', 'id')->where(fn ($q) => $q->where('scope', $request->input('scope'))->whereNull('reply_account_id')->where('id', '!=', $account?->id ?? 0))],
            'imap_host' => ['required', 'string', 'max:120'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'imap_username' => ['required', 'string', 'max:120'],
            'imap_password' => [$account ? 'nullable' : 'required', 'string', 'max:200'],
            'smtp_host' => ['required', 'string', 'max:120'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'smtp_username' => ['required', 'string', 'max:120'],
            'smtp_password' => [$account ? 'nullable' : 'required', 'string', 'max:200'],
            'signature' => ['nullable', 'string', 'max:5000'],
            'sync_from' => ['nullable', 'date'],
            'files_from' => ['nullable', 'date'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['imap_validate_cert'] = true;
        foreach (['imap_password', 'smtp_password'] as $secret) {
            if (empty($data[$secret])) {
                unset($data[$secret]);
            }
        }
        if (! $account) {
            $data['slug'] = Str::slug(Str::before($data['email'], '@')) ?: Str::random(6);
        }

        return $data;
    }
}
