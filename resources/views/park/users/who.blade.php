{{-- Человек в строке: аватар, имя, роль на парковке словом, телефон. Ожидает $user и $kind. --}}
<x-ui.avatar :user="$user" :size="36"/>
<span class="min-w-0 flex-1">
    <span class="block truncate">{{ $user->name }}</span>
    <span class="row-sub">
        <span @class(['text-accent-text' => $kind === 'admin'])>{{ mb_strtolower(\App\Http\Park\UserController::KINDS[$kind]) }}</span>
        @if ($user->phone)<span class="nums">{{ $user->phoneFormatted() }}</span>@endif
    </span>
</span>
