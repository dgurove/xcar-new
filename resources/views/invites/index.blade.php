{{-- Приглашения в кабинете на сайте — менеджеру и админу один экран; в CRM тот же список
     на «Пользователи → Ссылки». --}}
<x-ui.cabinet title="Приглашения">
    <div class="flex max-w-[56rem] flex-col gap-3"><x-invites.list :invites="$invites" :admin="$admin" :managers="$managers" :groups="$groups" :fresh="$fresh"/></div>
</x-ui.cabinet>
