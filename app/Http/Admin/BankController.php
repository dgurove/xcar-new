<?php

namespace App\Http\Admin;

use App\Billing\Bank\Actions\ConnectSber;
use App\Billing\Bank\Actions\IgnoreTransaction;
use App\Billing\Bank\Actions\ImportStatement;
use App\Billing\Bank\Actions\MatchTransaction;
use App\Billing\Bank\Actions\ReconcilePayout;
use App\Billing\Bank\Actions\SetBankAccount;
use App\Billing\Bank\Connection;
use App\Billing\Bank\SberApi;
use App\Billing\Bank\Transaction;
use App\Billing\Invoice;
use App\Support\Detail;
use App\Support\ListView;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Банк в CRM: «Поступления» из выписки (что не легло в счёт само — привязать руками или «не наше»)
 * и подключение СберБизнеса в настройках (директор входит в СберБизнес ID, счёт, «Загрузить сейчас»).
 */
class BankController
{
    public const PRESETS = ['unmatched' => 'Не привязаны', 'matched' => 'Привязаны', 'ignored' => 'Не наше', 'all' => 'Все входящие'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($tx = Transaction::find($key)) ? $this->detail($tx) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'unmatched';
        $qs = trim((string) $request->query('q'));
        $q = Transaction::where('direction', 'in')->with('invoice')
            // Лупа — по всем поступлениям, мимо пилюли.
            ->when($preset !== 'all' && $qs === '', fn ($w) => $w->where('state', $preset))
            ->when($qs !== '', fn ($w) => $w->where(fn ($s) => $s->where('counterparty', 'ilike', "%{$qs}%")->orWhere('purpose', 'ilike', "%{$qs}%")->orWhere('counterparty_inn', $qs)))
            ->latest('booked_at')->latest('id');

        return view('admin.bank.index', [
            'detail' => $detail,
            'transactions' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset, 'q' => $qs, 'counts' => array_filter(['unmatched' => Transaction::where('state', Transaction::UNMATCHED)->count()]),
            'connection' => Connection::sber(),
        ]);
    }

    public function detail(Transaction $transaction)
    {
        return view('admin.bank.detail', [
            'tx' => $transaction->load(['invoice.party', 'decider']),
            // Перечисление ЮMoney счёт не закрывает — вместо счетов на выбор оплаты по ссылкам, что в него вошли.
            'suggestions' => $transaction->state === Transaction::UNMATCHED && ! MatchTransaction::isPayout($transaction) ? MatchTransaction::suggestions($transaction)->take(12) : collect(),
            'payouts' => MatchTransaction::isPayout($transaction) ? ReconcilePayout::payments($transaction) : collect(),
        ]);
    }

    public function match(Request $request, Transaction $transaction, MatchTransaction $match)
    {
        $invoice = Invoice::findOrFail($request->validate(['invoice' => ['required', 'integer']])['invoice']);
        $match($transaction, $invoice, $request->user());

        return back()->with('toast', 'Привязано к счёту '.$invoice->label());
    }

    public function ignore(Request $request, Transaction $transaction, IgnoreTransaction $ignore)
    {
        $back = $transaction->state === Transaction::IGNORED;
        $ignore($transaction, $request->user(), ! $back, $request->input('note'));

        return back()->with('toast', $back ? 'Снова ждёт привязки' : 'Отмечено: не наше');
    }

    public function settings(Request $request, SberApi $api)
    {
        return view('admin.bank.settings', ['connection' => Connection::sber()->load('connector'), 'configured' => $api->configured(), 'admin' => $request->user()->isAdmin()]);
    }

    public function save(Request $request, SetBankAccount $set)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['account' => ['required', 'digits:20']], ['account.digits' => 'Расчётный счёт — 20 цифр']);
        $set(Connection::sber(), $data['account']);

        return back()->with('toast', 'Сохранено');
    }

    /** Директор уходит в СберБизнес ID за согласием; вернётся на callback. */
    public function connect(Request $request, SberApi $api)
    {
        abort_unless($request->user()->isAdmin() && $api->configured(), 403);

        return redirect()->away($api->authorizeUrl(Connection::sber()));
    }

    public function callback(Request $request, ConnectSber $connect)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $connection = Connection::sber();
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/settings/bank')->with('toast', 'Сбер не подключил: '.($request->input('error_description') ?: $request->input('error') ?: 'нет кода'));
        }
        abort_unless($connection->state && hash_equals($connection->state, (string) $request->input('state')), 403);
        try {
            $connect($connection, (string) $request->input('code'), $request->user());
        } catch (Throwable $e) {
            Log::warning('bank: подключение — '.$e->getMessage());

            return redirect('/settings/bank')->with('toast', $e->getMessage());
        }

        return redirect('/settings/bank')->with('toast', 'СберБизнес подключён');
    }

    public function sync(Request $request, ImportStatement $import)
    {
        $connection = Connection::sber();
        abort_unless($connection->connected() && $connection->account, 404);
        try {
            $fresh = $import($connection, now()->startOfDay());
        } catch (Throwable $e) {
            return back()->with('toast', 'Не загрузилось: '.$e->getMessage());
        }

        return back()->with('toast', $fresh === null ? 'Банк ещё готовит выписку, загрузим позже' : ($fresh->isEmpty() ? 'Выписка загружена' : 'Без счёта: '.$fresh->count().' на '.Money::rub($fresh->sum('amount'))));
    }
}
