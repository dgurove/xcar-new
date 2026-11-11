{{-- Покупатель без доступа к предложениям (11.11.2026, `User::hasBuyerAccess`): менеджер его не открывал или закрыл —
     открыть может только он (`GiveBuyerAccess`). Сессия уже закрыта (`SiteWall`), здесь — только что делать. --}}
<x-ui.auth title="Доступ закрыт">
    <p class="mt-4 text-ink-muted">Доступ к предложениям открывает ваш менеджер. Напишите ему или нам.</p>
    <div class="mt-6 flex flex-col gap-2">
        <a href="{{ \App\Support\Surface::Site->url('/contacts') }}" class="btn btn-quiet w-full"><x-ui.icon name="mail" class="size-5"/> Написать нам</a>
        <a href="/login" class="btn btn-ghost w-full">Войти другим</a>
    </div>
</x-ui.auth>
