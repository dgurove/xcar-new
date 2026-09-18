{{-- Контакты по ролям: первая строка — новый; сама строка — правка в шторке; звонок и письмо — круглыми справа. --}}
@php use App\Vendors\ContactRole; @endphp
<div class="flex flex-col gap-2">
    <div data-controller="sheet">
        <button type="button" class="row w-full text-left" data-action="sheet#open">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Новый контакт</span>
        </button>
        <x-ui.sheet id="contact-new" title="Контакт" :open="$errors->has('name') && !old('contact_id')">
            @include('admin.vendors.contact-form', ['contact' => null, 'action' => $base.'/contacts', 'method' => 'post'])
        </x-ui.sheet>
    </div>
    @foreach ($vendor->contacts as $c)
        <div class="row" data-controller="sheet">
            <button type="button" class="contents text-left" data-action="sheet#open">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="user" class="size-5"/></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">{{ $c->name }}</span>
                    <span class="row-sub mt-1 flex flex-wrap gap-1.5">
                        <span class="chip">{{ $c->role->label() }}</span>
                        @if ($c->is_default)<span class="chip">основной</span>@endif
                        @if ($c->always_cc)<span class="chip">в копии</span>@endif
                        @if ($c->title)<span class="text-sm text-ink-muted">{{ $c->title }}</span>@endif
                    </span>
                </span>
            </button>
            @if ($c->phone)<a href="tel:+{{ $c->phoneDigits() }}" class="btn btn-quiet btn-round btn-s" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
            @if ($c->email)<a href="/work/mail/new?to={{ urlencode($c->email) }}" class="btn btn-quiet btn-round btn-s" aria-label="Написать"><x-ui.icon name="mail" class="size-5"/></a>@endif
            <x-ui.sheet id="contact-{{ $c->id }}" title="Контакт" :open="$errors->has('name') && (int) old('contact_id') === $c->id">
                @include('admin.vendors.contact-form', ['contact' => $c, 'action' => $base.'/contacts/'.$c->id, 'method' => 'put'])
                <form method="post" action="{{ $base }}/contacts/{{ $c->id }}" class="mt-3" data-turbo-confirm="Удалить контакт?">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить</x-ui.button></form>
            </x-ui.sheet>
        </div>
    @endforeach
</div>
