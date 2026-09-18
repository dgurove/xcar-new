{{-- Обзор: ТС на стоянке сейчас, предложения в работе, договор. Пустое не рисуется. --}}
@php use App\Support\Money; use App\Support\Surface; use App\Vendors\RewardKind; @endphp
<div class="flex flex-col gap-8">
    <section>
        <h2 class="text-xl">Условия</h2>
        <div class="mt-4 flex flex-wrap gap-1.5">
            <span class="tag">{{ $vendor->vat_included ? 'с НДС' : 'без НДС' }}</span>
            @if ($vendor->reward_kind)<span class="tag">{{ $vendor->reward_kind === RewardKind::Difference ? 'вознаграждение — разница цен' : ($vendor->reward_kind === RewardKind::Percent ? 'вознаграждение '.$vendor->reward_value.' %' : 'вознаграждение '.Money::rub($vendor->reward_value)) }}</span>@endif
            @if ($vendor->payment_days !== null)<span class="tag nums">оплата {{ $vendor->payment_days }} раб. дн</span>@endif
            @if ($vendor->answer_hours)<span class="tag nums">ответ {{ $vendor->answer_hours }} ч</span>@endif
            @if ($vendor->binding_days)<span class="tag nums">держим {{ $vendor->binding_days }} дн</span>@endif
            <span class="tag">хранение платит {{ ['vendor' => 'вендор', 'owner' => 'страхователь', 'nobody' => 'никто'][$vendor->storage_payer] ?? $vendor->storage_payer }}</span>
            @if ($vendor->buyer_storage_after_days !== null)<span class="tag nums">покупатель с {{ $vendor->buyer_storage_after_days }} дня</span>@endif
            @if ($vendor->release_without_payment)<span class="tag">выдача без оплаты</span>@endif
            @if ($vendor->legal_name)<span class="tag">{{ $vendor->legal_name }}</span>@endif
            @if ($vendor->inn)<span class="tag nums">ИНН {{ $vendor->inn }}</span>@endif
            @if ($vendor->bank_account)<span class="tag nums">р/с {{ $vendor->bank_account }}</span>@endif
            @foreach ($vendor->senders ?? [] as $s)<span class="tag">{{ $s }}</span>@endforeach
            @if ($vendor->mailAccount)<span class="tag">с ящика {{ $vendor->mailAccount->email }}</span>@endif
        </div>
    </section>
    @if ($vehicles->isNotEmpty())
        <section>
            <h2 class="text-xl">На стоянке <span class="nums text-ink-dim">{{ $vehicles->count() }}</span></h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($vehicles as $v)
                    <a href="{{ Surface::Park->url('/cars/'.$v->id) }}" class="row" data-turbo="false">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $v->titleWithYear() }}</div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @if ($v->ref)<span class="tag nums">{{ $v->ref }}</span>@endif
                                @if ($v->yard)<x-ui.place class="tag">{{ $v->yard->name }}</x-ui.place>@endif
                                @if ($v->category)<span class="tag">{{ $v->category->label() }}</span>@endif
                            </div>
                        </div>
                        <span class="nums shrink-0 text-sm text-ink-muted">{{ $v->daysStored() }} дн</span>
                        <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($offers->isNotEmpty())
        <section>
            <h2 class="text-xl">Предложения <span class="nums text-ink-dim">{{ $offersTotal }}</span></h2>
            <div class="mt-4 flex flex-col gap-2">
                @foreach ($offers as $o)
                    <a href="/offers/{{ $o->number }}" class="row">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $o->titleWithYear() }}</div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <span class="tag nums">№ {{ $o->number }}</span>
                                <span class="chip">{{ $o->state->label() }}</span>
                                @if ($o->claim_ref)<span class="tag nums">{{ $o->claim_ref }}</span>@endif
                                @if ($o->answer_by)<x-ui.pill :tone="$o->answer_by->isPast() ? 'danger' : 'urgent'" class="!min-h-0 !py-0.5 text-xs">до {{ $o->answer_by->translatedFormat('j M H:i') }}</x-ui.pill>@endif
                            </div>
                        </div>
                        @if ($o->floor_price)<span class="nums shrink-0 text-sm font-semibold">{{ Money::rub($o->floor_price) }}</span>@endif
                        <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
                    </a>
                @endforeach
                @if ($offersTotal > $offers->count())<a href="/offers?vendor={{ $vendor->id }}" class="btn btn-ghost btn-s self-start">Все предложения</a>@endif
            </div>
        </section>
    @endif

    <section>
        <h2 class="text-xl">Договор</h2>
        <div class="mt-4 flex flex-col gap-3">
            @if ($contract)
                <x-ui.file :name="$contract->file_name" :href="'/files/'.$contract->id" :size="$contract->humanReadableSize" :mime="$contract->mime_type" download>
                    <form method="post" action="{{ $base }}/contract" data-turbo-confirm="Убрать файл договора?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="trash" class="size-5"/></button></form>
                </x-ui.file>
            @endif
            <form method="post" action="{{ $base }}/contract" enctype="multipart/form-data" class="flex items-end gap-2">
                @csrf
                <x-ui.field name="file" type="file" label="{{ $contract ? 'Заменить файл' : 'Файл договора' }}" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" span="flex-1"/>
                <x-ui.button variant="secondary">Приложить</x-ui.button>
            </form>
        </div>
    </section>

    @if ($vendor->intake_docs || $vendor->intake_note)
        <section>
            <h2 class="text-xl">После приёма присылаем</h2>
            <div class="mt-4 flex flex-wrap gap-1.5">
                @foreach ($vendor->intake_docs ?? [] as $d)@if ($doc = \App\Vendors\DocRequirement::tryFrom($d))<span class="chip">{{ $doc->label() }}</span>@endif @endforeach
            </div>
            @if ($vendor->intake_note)<p class="mt-3 text-sm text-ink-muted">{{ $vendor->intake_note }}</p>@endif
        </section>
    @endif

    @if ($vendor->notes)
        <section>
            <h2 class="text-xl">Заметки</h2>
            <p class="mt-3 whitespace-pre-line text-sm">{{ $vendor->notes }}</p>
        </section>
    @endif
</div>
