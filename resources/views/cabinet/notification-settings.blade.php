{{-- Шторка настроек уведомлений: пуш на это устройство и дубль на почту. Открывается из профиля и с экрана уведомлений. --}}
@php $user = auth()->user(); @endphp
<x-ui.sheet id="notification-settings" title="Уведомления">
    @if (config('xcar.vapid.public'))
    <div class="mb-4 flex flex-col gap-2" data-controller="push">
        <x-ui.button type="button" block data-push-target="on" data-action="push#enable"><x-ui.icon name="bell" class="size-5"/> Уведомления на телефон</x-ui.button>
        <x-ui.button type="button" variant="secondary" block hidden data-push-target="off" data-action="push#disable">Выключить уведомления на телефоне</x-ui.button>
        <div class="text-sm text-danger" data-push-target="state"></div>
    </div>
    @endif
    <form method="post" action="/lk/uvedomleniya/nastroyki" class="flex flex-col gap-4">
        @csrf @method('put')
        <x-ui.check name="mail" :checked="$user->wantsMail()">Дублировать на почту{{ $user->email ? ' '.$user->email : '' }}</x-ui.check>
        <x-ui.button block>Сохранить</x-ui.button>
    </form>
</x-ui.sheet>
