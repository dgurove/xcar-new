{{-- Люди парковки — администратору парковки: пилюли «Люди» и «Ссылки». Человек строкой (аватар, имя, роль словом,
     телефон), нажатие — шторка: роль выбором и «Закрыть доступ к парковке»; себя не правят. «Ссылки» — первой строкой
     новая (управляющему или помощнику, одноразовая со сроком), дальше общий `invites.row`. --}}
@php
    use App\Http\Cabinet\InviteController;
    use App\Http\Park\UserController;
    $me = auth()->user();
    $base = InviteController::base();
    $admin = false;
    $kindOf = fn ($u) => $u->isParkAdmin() ? 'admin' : ($u->park_readonly ? 'helper' : 'manager');
    [$live, $past] = $invites->partition(fn ($i) => $i->isActive());
@endphp
<x-ui.cabinet title="Пользователи">
    <x-ui.pills class="mb-4">
        @foreach ($pills as $key => $label)
            <x-ui.pill :href="'/users'.($key === 'people' ? '' : '?preset='.$key)" :current="$pill === $key" data-turbo-action="replace">{{ $label }}@if ($counts[$key]) <span class="nums">{{ $counts[$key] }}</span>@endif</x-ui.pill>
        @endforeach
    </x-ui.pills>

    @if ($pill === 'people')
        <div class="list">
            @foreach ($people as $user)
                @php $kind = $kindOf($user); $self = $user->is($me); @endphp
                <div class="contents" @unless ($self) data-controller="sheet" @endunless>
                    @if ($self)
                        <div class="row">@include('park.users.who')</div>
                    @else
                        <x-ui.sheet-row>@include('park.users.who')<x-ui.chevron/></x-ui.sheet-row>
                    @endif
                    @unless ($self)
                        <x-ui.sheet id="user-{{ $user->id }}" :title="$user->name">
                            <form method="post" action="/users/{{ $user->id }}" class="flex flex-col gap-4">
                                @csrf @method('put')
                                <div class="field">
                                    <span class="field-label">Роль на парковке</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (UserController::KINDS as $key => $label)
                                            <label class="choice"><input type="radio" name="kind" value="{{ $key }}" @checked($kind === $key)><span>{{ $label }}</span></label>
                                        @endforeach
                                    </div>
                                </div>
                                <x-park.city-field :city="$user->park_city_id"/>
                                <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
                            </form>
                            <form method="post" action="/users/{{ $user->id }}/revoke" class="mt-2" data-turbo-confirm="Закрыть {{ $user->shortName() }} доступ к парковке?">
                                @csrf
                                <x-ui.button variant="ghost" block>Закрыть доступ к парковке</x-ui.button>
                            </form>
                        </x-ui.sheet>
                    @endunless
                </div>
            @endforeach
        </div>
    @else
        <div class="list">
            <div data-controller="sheet" class="contents">
                <button type="button" class="row w-full text-left transition-colors hover:bg-hover" data-action="sheet#open">
                    <x-ui.row-icon name="plus" tone="accent"/>
                    <span class="min-w-0 flex-1 font-medium">Новая ссылка</span>
                </button>
                <x-ui.sheet id="invite-new" title="Новая ссылка">
                    <form method="post" action="/users/invites" class="flex flex-col gap-3">
                        @csrf
                        <div class="fields">
                            <x-ui.field name="expires_at" label="Действует до" type="datetime-local" :value="now()->addDays(3)->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')"/>
                        </div>
                        <x-park.access-fields/>
                        <x-ui.sheet-foot>Создать ссылку</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
            @foreach ($live as $invite)
                @include('invites.row')
            @endforeach
        </div>
        @if ($past->isNotEmpty())
            <section>
                <h2 class="list-head">Сработали и выключенные</h2>
                <div class="list">
                    @foreach ($past as $invite)
                        @include('invites.row')
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</x-ui.cabinet>
