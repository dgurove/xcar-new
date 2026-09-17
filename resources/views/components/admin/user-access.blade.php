{{-- Допуск ждущего: роль и «Открыть» / «Отклонить» — в строке списка и на карточке. --}}
@props(['user', 'base'])
@php use App\Users\Role; use App\Http\Admin\UserController; @endphp
<form method="post" action="{{ $base }}/{{ $user->id }}/access" class="flex items-center gap-1.5">
    @csrf
    <select name="role" class="field-input field-s !w-auto" aria-label="Роль">@foreach (UserController::ROLES as $r)<option value="{{ $r->value }}" @selected($r === Role::Manager)>{{ $r->label() }}</option>@endforeach</select>
    <x-ui.button size="sm">Открыть</x-ui.button>
    @unless ($user->isRejected())<x-ui.button size="sm" variant="ghost" name="reject" value="1" data-turbo-confirm="Отклонить {{ $user->name }}?">Отклонить</x-ui.button>@endunless
</form>
