{{-- Деньги менеджера: положение строками-кнопками (оплатить / к выплате / реквизиты), пилюли пресетов и
     список сделок-расчётов — одна сделка, одна фраза, одно число. Реквизиты и документы — в «···» справа от пилюль.
     Колонка узкая и на компьютере. --}}
@php use App\Support\Money; use App\Billing\DealMoney; $needDetails = ! $party->filled() || ! $party->payoutReady(); @endphp
<x-ui.cabinet title="Деньги">
    <div class="flex max-w-[30rem] flex-col gap-6">

        @if ($position['pay'] > 0 || $position['payout'] > 0 || ($needDetails && ($position['payout'] > 0 || $position['paid_out'] > 0)))
            <div class="list">
                @if ($position['pay'] > 0)
                    @php $overdue = $position['overdue'] > 0 && $position['claimed'] < $position['overdue']; @endphp
                    <a href="/account/money?preset=pay" class="row {{ $overdue ? 'bg-urgent-soft' : '' }}" data-turbo-action="replace">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $overdue ? 'bg-urgent text-white' : 'bg-surface-3 text-ink' }}"><x-ui.icon name="file" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium {{ $overdue ? 'text-urgent' : '' }}">Оплатить {{ Money::rub($position['pay']) }}</span>
                            <span class="block text-sm {{ $overdue ? 'text-urgent' : 'text-ink-muted' }}">{{ $position['claimed'] > 0 ? 'сообщили об оплате '.Money::rub($position['claimed']).', ждёт подтверждения' : ($overdue ? 'просрочено '.Money::rub($position['overdue']) : 'до '.$position['pay_due']->translatedFormat('j M')) }}</span>
                        </span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 {{ $overdue ? 'text-urgent' : 'text-ink-dim' }}"/>
                    </a>
                @endif
                @if ($position['payout'] > 0)
                    <a href="/account/money?preset=payout" class="row" data-turbo-action="replace">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="deal" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">Вам к выплате {{ Money::rub($position['payout']) }}</span>
                            <span class="block text-sm text-ink-muted">до {{ $position['payout_due']->translatedFormat('j M') }}</span>
                        </span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                    </a>
                @endif
                @if ($needDetails && ($position['payout'] > 0 || $position['paid_out'] > 0))
                    <a href="/account/money/details" class="row bg-urgent-soft">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-urgent text-white"><x-ui.icon name="user" class="size-5"/></span>
                        <span class="min-w-0 flex-1 font-medium text-urgent">Реквизиты для выплат не указаны</span>
                        <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-urgent"/>
                    </a>
                @endif
            </div>
        @endif

            <x-ui.toolbar :pills="DealMoney::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['pay' => $position['overdue'] > 0 ? 'pill-danger' : '']" name="money" action="/account/money">
                <x-slot:pillsExtra>
                    <div class="ml-1 shrink-0 self-center" data-controller="sheet">
                        <button type="button" class="btn btn-s btn-quiet btn-round" data-action="sheet#open" aria-label="Реквизиты и документы"><x-ui.icon name="more" class="size-5"/></button>
                        <x-ui.sheet id="money-more" title="Деньги">
                            <div class="list">
                                <a href="/account/money/details" class="row">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-medium">Реквизиты</span>
                                        <span class="row-sub">@if ($party->filled())<span class="tag">{{ $party->kind->label() }}</span>@if ($party->bankDetails())<span class="tag truncate">{{ $party->bank_name ?: 'карта' }}</span>@endif @else<span class="tag">не указаны</span>@endif</span>
                                    </span>
                                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                                </a>
                                {{-- Акт сверки и Excel — одна форма, две кнопки; на телефоне во встроенный браузер. --}}
                                <form method="get" action="/account/money/statement" class="mt-2 flex flex-col gap-3" data-turbo="false" data-controller="file" data-action="submit->file#share">
                                    <div class="grid grid-cols-2 gap-3">
                                        <x-ui.field name="from" label="С" type="date" :value="now()->startOfMonth()->toDateString()"/>
                                        <x-ui.field name="to" label="По" type="date" :value="now()->toDateString()"/>
                                    </div>
                                    <x-ui.button block variant="secondary" data-file-any>Акт сверки, PDF</x-ui.button>
                                    <x-ui.button block variant="secondary" formaction="/account/money/export" data-file-any>Сделки, Excel</x-ui.button>
                                </form>
                            </div>
                        </x-ui.sheet>
                    </div>
                </x-slot:pillsExtra>
            </x-ui.toolbar>
            @if ($deals->isEmpty())
                <x-ui.empty>{{ match ($preset) { 'pay' => 'Платить нечего', 'payout' => 'Выплат не ждёт', 'closed' => 'Закрытых ещё нет', default => 'Сделок с деньгами пока нет' } }}</x-ui.empty>
            @else
                <div class="list">
                    @foreach ($deals as $deal)
                        <x-money.deal-row :deal="$deal" :href="'/account/money/deals/'.$deal->id"/>
                    @endforeach
                </div>
            @endif
    </div>
</x-ui.cabinet>
