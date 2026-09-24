{{-- Контакты вендора плашкой: строка — правка в шторке, звонок и письмо — круглыми справа; последней строкой
     «Новый контакт». Парковка у контакта — у кого убытки по городу: машины из его писем заводятся сразу на неё. --}}
@php $manage = auth()->user()->canManagePark(); @endphp
<div class="list">
    @foreach ($vendor->contacts as $c)
        <div class="row" data-controller="sheet">
            <button type="button" class="contents text-left" @if ($manage) data-action="sheet#open" @endif>
                <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ $c->name }}</span>
                    <span class="row-sub">
                        <span>{{ $c->role->label() }}</span>
                        @if ($c->is_default)<span>основной</span>@endif
                        @if ($c->always_cc)<span>в копии</span>@endif
                        @if ($c->yard)<span>{{ $c->yard->name }}</span>@endif
                        @if ($c->title)<span>{{ $c->title }}</span>@endif
                    </span>
                </span>
            </button>
            @if ($c->phone)<a href="tel:+{{ $c->phoneDigits() }}" class="btn btn-quiet btn-round btn-s shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
            @if ($c->email)<a href="mailto:{{ $c->email }}" class="btn btn-quiet btn-round btn-s shrink-0" aria-label="Написать"><x-ui.icon name="mail" class="size-5"/></a>@endif
            @if ($manage)
                <x-ui.sheet id="contact-{{ $c->id }}" title="Контакт" :open="$errors->has('name') && (int) old('contact_id') === $c->id">
                    @include('park.vendors.contact-form', ['contact' => $c, 'action' => $base.'/contacts/'.$c->id, 'method' => 'put'])
                    <form method="post" action="{{ $base }}/contacts/{{ $c->id }}" class="mt-3" data-turbo-confirm="Удалить контакт?">@csrf @method('delete')<x-ui.button variant="danger" block>Удалить</x-ui.button></form>
                </x-ui.sheet>
            @endif
        </div>
    @endforeach
    @if ($manage)
        <div data-controller="sheet">
            <button type="button" class="row w-full text-left text-accent-text" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/><span>Новый контакт</span></button>
            <x-ui.sheet id="contact-new" title="Контакт" :open="$errors->has('name') && ! old('contact_id')">
                @include('park.vendors.contact-form', ['contact' => null, 'action' => $base.'/contacts', 'method' => 'post'])
            </x-ui.sheet>
        </div>
    @endif
</div>
