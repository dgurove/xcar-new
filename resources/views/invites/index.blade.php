{{-- Приглашения в кабинете на сайте — менеджеру и админу один экран; в CRM тот же список
     на «Пользователи → Ссылки». --}}
<x-ui.cabinet title="Приглашения">
    <x-invites.list :invites="$invites" :admin="$admin" :managers="$managers" :groups="$groups" :fresh="$fresh"/>
</x-ui.cabinet>
