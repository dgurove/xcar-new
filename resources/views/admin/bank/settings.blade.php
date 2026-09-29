{{-- Банк: подключение СберБизнеса для выписки (директор входит в СберБизнес ID, счёт, «Загрузить сейчас») и
     состояние ЮKassa для оплаты по ссылке с адресом уведомлений, который вписывают в её кабинет. --}}
@php $c = $connection; $hook = \App\Support\Surface::Site->url('/hooks/yookassa'); @endphp
<x-ui.cabinet title="Банк">
    <div class="flex max-w-[36rem] flex-col gap-6">
        <div>
            <h2 class="list-head">СберБизнес</h2>
            <div class="list">
                <div class="row justify-between">
                    <span class="min-w-0">
                        <span class="block">{{ $c->connected() ? 'Подключён' : 'Не подключён' }}</span>
                        @if ($c->connected())<span class="row-sub">{{ $c->connector?->shortName() }}, {{ $c->connected_at?->translatedFormat('j M Y') }}, до {{ $c->refresh_expires_at->translatedFormat('j M Y') }}</span>@endif
                    </span>
                    @if ($admin && $configured)
                        <form method="post" action="/settings/bank/connect" data-turbo="false">@csrf<x-ui.button size="sm" :variant="$c->connected() ? 'secondary' : 'primary'">{{ $c->connected() ? 'Подключить заново' : 'Подключить' }}</x-ui.button></form>
                    @endif
                </div>
                @unless ($configured)
                    <div class="row text-ink-muted">Нет доступа к Sber API на сервере</div>
                @endunless
                @if ($c->connected() && $c->account)
                    <form method="post" action="/settings/bank/sync">@csrf
                        <button class="row w-full justify-between text-left">
                            <span class="min-w-0">
                                <span class="block">Загрузить выписку сейчас</span>
                                @if ($c->last_error)<span class="row-sub text-danger">{{ \Illuminate\Support\Str::limit($c->last_error, 120) }}</span>
                                @elseif ($c->synced_at)<span class="row-sub">загружена {{ $c->synced_at->diffForHumans() }}</span>@endif
                            </span>
                            <x-ui.icon name="refresh" class="size-5 shrink-0 text-ink-dim"/>
                        </button>
                    </form>
                @endif
                <a href="/work/money/bank" class="row justify-between"><span>Поступления</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            </div>
            @if ($admin)
                <form method="post" action="/settings/bank" class="mt-3 flex items-end gap-2">
                    @csrf @method('put')
                    <x-ui.field name="account" label="Расчётный счёт" :value="$c->account" maxlength="20" class="nums" span="flex-1"/>
                    <x-ui.button variant="secondary">Сохранить</x-ui.button>
                </form>
            @endif
        </div>

        <div>
            <h2 class="list-head">ЮKassa</h2>
            <div class="list">
                <div class="row">{{ app(\App\Billing\Acquiring\Gateway::class)->configured() ? 'Подключена' : 'Не подключена' }}</div>
            </div>
            <div class="mt-3"><x-ui.field name="hook" label="Адрес для уведомлений" :value="$hook" readonly copy/></div>
        </div>
    </div>
</x-ui.cabinet>
