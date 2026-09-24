{{-- Карточка человека для сотрудников: контакт как у покупателя в кабинете (кружок, имя, чипы, ряд действий),
     ниже его чаты, у менеджера сделки, у покупателя интерес. Админу — «Изменить» тем же шитом, что в списке,
     ждущему — форма допуска. Из шапки чата открывается с «‹ Чат». --}}
@php use App\Users\Section; use App\Http\Admin\UserController; $me = auth()->user(); $base = UserController::base(); $crm = \App\Support\Surface::current() === \App\Support\Surface::Crm; $link = ($link['user'] ?? null) === $user->id ? $link : null; @endphp
<x-ui.cabinet :title="$user->name" :back="$back">

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="lg:col-start-2 lg:row-start-1" data-controller="sheet">
            <x-ui.contact :name="$user->name" :user="$user" sidebar>
                <x-slot:chips>
                    <x-ui.pill :tone="$user->isAdmin() ? 'soft' : ($user->isStaff() ? 'plain' : 'closed')" class="!min-h-0 !py-0.5 text-xs">{{ $user->role->label() }}</x-ui.pill>
                    @if ($user->canAccess(Section::Park) && !$user->isAdmin())<span class="chip text-xs">Парковка</span>@endif
                    @if ($user->isPending())<x-ui.pill tone="urgent" class="!min-h-0 !py-0.5 text-xs">Ждёт</x-ui.pill>@elseif ($user->isRejected())<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">Отклонён</x-ui.pill>@endif
                    @if ($user->isBuyer() && $user->manager)<a href="{{ $base }}/{{ $user->manager_id }}" class="chip person"><x-ui.avatar :user="$user->manager" :size="20"/>{{ $user->manager->shortName() }}</a>@endif
                    @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                    @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="tag nums">{{ $user->phoneFormatted() }}</a>@endif
                    @if ($user->email)<a href="mailto:{{ $user->email }}" class="tag">{{ $user->email }}</a>@endif
                    <span class="tag nums">с {{ $user->created_at->translatedFormat('j M Y') }}</span>
                    @if ($user->isManager())<a href="{{ $base }}?preset=buyers&manager={{ $user->id }}" class="tag">{{ $buyersCount }} {{ \App\Support\Plural::of($buyersCount, ['покупатель', 'покупателя', 'покупателей']) }}</a>@endif
                    @if ($seen !== null)<span class="tag">видит {{ $seen }}</span>@endif
                    @if (! $user->isBuyer() && $user->invite?->creator)<x-ui.person :user="$user->invite->creator" full prefix="по ссылке"/>@endif
                </x-slot:chips>
                <x-slot:acts>
                    @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>@endif
                    @if ($user->email)<a href="mailto:{{ $user->email }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="mail"/></span>Написать</a>@endif
                    @if ($me->isAdmin())<button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="edit"/></span>Изменить</button>@endif
                </x-slot:acts>
            </x-ui.contact>
            @if ($me->isAdmin() && !$user->isApproved() && !$user->is($me))
                <div class="mt-4 flex lg:justify-center"><x-admin.user-access :user="$user" :base="$base"/></div>
            @endif
            @if ($me->isAdmin())<x-admin.user-sheet :user="$user" :managers="$managers" :link="$link" :base="$base" :me="$me"/>@endif
        </div>

        <div class="min-w-0 lg:col-start-1 lg:row-start-1">
            @if ($user->isManager())
                {{-- У менеджера два экрана: обзор и деньги — как у вендора. --}}
                <div class="mb-5 flex flex-wrap gap-1.5">
                    <x-ui.pill :href="$base.'/'.$user->id" :current="$pill === 'overview'">Обзор</x-ui.pill>
                    <x-ui.pill :href="$base.'/'.$user->id.'?pill=money'" :current="$pill === 'money'">Деньги</x-ui.pill>
                </div>
            @endif
            @if ($pill === 'money')
                @include('admin.users.money')
            @else
            <section>
                <h2 class="text-xl">Чаты @if ($chats->isNotEmpty())<span class="nums text-ink-dim">{{ $chats->count() }}</span>@endif</h2>
                @if ($chats->isEmpty())
                    <x-ui.empty class="mt-4">Чатов нет</x-ui.empty>
                @else
                    <div class="chat-rows-list mt-4 rounded-(--radius-l) border border-line">
                        @foreach ($chats as $c)
                            <x-chat.row :chat="$c" :me="$me" :href="($crm ? '/work/chats/' : '/account/chats/').$c->id" staff/>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($deals->isNotEmpty())
                <section class="mt-8">
                    <h2 class="text-xl">Сделки <span class="nums text-ink-dim">{{ $deals->count() }}</span></h2>
                    <div class="list mt-3">
                        @foreach ($deals as $deal)
                            @include('admin.deals.row', ['deal' => $deal, 'person' => false])
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($interests->isNotEmpty())
                <section class="mt-8">
                    <h2 class="text-xl">Интерес <span class="nums text-ink-dim">{{ $interests->count() }}</span></h2>
                    <div class="list mt-3">
                        @foreach ($interests as $interest)
                            @php $offer = $interest->offer; $price = \App\Offers\PriceView::for($offer, $me); @endphp
                            <a href="/offers/{{ $offer->number }}" class="row">
                                <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                                    <span class="row-sub">
                                        @if ($interest->state !== \App\Offers\InterestState::New)<span>{{ mb_strtolower($interest->state->label()) }}</span>@endif
                                        @if ($price->shown())<span class="nums">{{ $price::money($price->to) }}&nbsp;₽</span>@endif
                                    </span>
                                    @if ($interest->comment)<span class="mt-1.5 block text-sm">{{ $interest->comment }}</span>@endif
                                </span>
                                <span class="nums shrink-0 text-sm text-ink-dim">{{ $interest->created_at->translatedFormat($interest->created_at->isToday() ? 'H:i' : 'j M') }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @endif
        </div>
    </div>
</x-ui.cabinet>
