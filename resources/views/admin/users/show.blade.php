{{-- Карточка человека для сотрудников: контакт как у покупателя в кабинете (кружок, имя, чипы, ряд действий),
     ниже его чаты, у менеджера сделки, у покупателя интерес. Админу — «Изменить» тем же шитом, что в списке,
     ждущему — форма допуска. Из шапки чата открывается с «‹ Чат». --}}
@php use App\Http\Admin\UserController; $me = auth()->user(); $base = UserController::base(); $crm = \App\Support\Surface::current() === \App\Support\Surface::Crm; $link = ($link['user'] ?? null) === $user->id ? $link : null; @endphp
<x-ui.cabinet :title="$user->name" :back="$back" :phone-heading="false">

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="lg:col-start-2 lg:row-start-1" data-controller="sheet">
            <x-ui.contact :name="$user->name" :user="$user" sidebar>
                <x-slot:chips>
                    <x-ui.state :tone="$user->isAdmin() ? 'soft' : ($user->isStaff() ? 'plain' : 'closed')">{{ $user->role->label().' с '.$user->created_at->format('d.m.Y') }}</x-ui.state>
                    {{-- Группа модератора: с кем он видит и правит предложения друг друга. --}}
                    {{-- Группы человека (менеджеров или модераторов) — ведутся в «Пользователях». --}}
                    @foreach ($user->userGroups as $g)<span class="tag">{{ $g->name }}</span>@endforeach
                    @if ($user->isPending())<x-ui.state tone="urgent">Ждёт</x-ui.state>@elseif ($user->isRejected())<x-ui.state tone="danger">Отклонён</x-ui.state>@endif
                    @if ($user->isBuyer() && $user->manager)<a href="{{ $base }}/{{ $user->manager_id }}" class="chip person"><x-ui.avatar :user="$user->manager" :size="20"/>{{ $user->manager->shortName() }}</a>@endif
                    @if ($user->login)<span class="tag nums">{{ $user->login }}</span>@endif
                    @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="tag nums">{{ $user->phoneFormatted() }}</a>@endif
                    @if ($user->email)<a href="mailto:{{ $user->email }}" class="tag">{{ $user->email }}</a>@endif
                    @if ($user->telegram_username)<a href="https://t.me/{{ $user->telegram_username }}" target="_blank" rel="noopener" data-turbo="false" class="tag tag-telegram"><x-telegram.logo plain class="size-3.5"/>{{ $user->telegram_username }}</a>@elseif ($user->telegram_chat_id)<span class="tag tag-telegram"><x-telegram.logo plain class="size-3.5"/>Telegram</span>@endif
                    @if ($user->isManager())<a href="{{ $base }}?preset=buyers&manager={{ $user->id }}" class="tag">{{ $buyersCount }} {{ \App\Support\Plural::of($buyersCount, ['покупатель', 'покупателя', 'покупателей']) }}</a>@endif
                    @if ($seen !== null)<span class="tag">видит {{ $seen }}</span>@endif
                </x-slot:chips>
                <x-slot:acts>
                    @if ($user->phone)<a href="tel:+{{ $user->phone }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="phone"/></span>Позвонить</a>@endif
                    @if ($user->email)<a href="mailto:{{ $user->email }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="mail"/></span>Написать</a>@endif
                    @if ($me->isAdmin() && $user->telegram_chat_id && \App\Support\Surface::current() === \App\Support\Surface::Crm)<a href="/settings/telegram/{{ $user->telegram_chat_id }}" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="chat"/></span>Переписка</a>@endif
                    @if ($me->isAdmin())<button type="button" class="act" data-action="sheet#open"><span class="btn btn-quiet btn-round"><x-ui.icon name="edit"/></span>Изменить</button>@endif
                    @if (\App\Users\Impersonation::allowed($me, $user) && $user->isApproved())
                        <form method="post" action="{{ $base }}/{{ $user->id }}/impersonate" class="contents"
                            data-turbo-confirm="Войти как {{ $user->shortName() }}?" data-turbo-confirm-label="Получить ссылку"
                            data-turbo-confirm-text="Одноразовая ссылка на {{ \App\Users\Impersonation::MINUTES }} минут. Откройте её в окне инкогнито, иначе в этом окне вы выйдете">
                            @csrf<button type="submit" class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="login"/></span>Войти как</button>
                        </form>
                    @endif
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
                    <div class="chat-rows-list mt-4">
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
