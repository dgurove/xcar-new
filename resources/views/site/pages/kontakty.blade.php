<x-ui.shell title="Контакты" :trail="[['Главная', '/'], ['Контакты']]" narrow>
    <dl>
        <div>
            <dt class="text-sm text-ink-dim">Почта</dt>
            <dd class="mt-1"><a href="mailto:hello@xcar.ru" class="text-accent-text hover:underline">hello@xcar.ru</a></dd>
        </div>
    </dl>

    @unless ($staff)
    <section class="mt-10">
        <h2 class="text-xl">Написать нам</h2>
        <div class="box mt-4">
            @if ($chat)
                <x-chat.box :chat="$chat" :messages="$messages" :user="$user"/>
            @else
                <form method="post" action="/kontakty" class="flex flex-col gap-3">
                    @csrf
                    <input type="text" name="website" tabindex="-1" autocomplete="off" class="absolute left-[-9999px]" aria-hidden="true">
                    @guest<x-ui.field name="name" placeholder="Имя" autocomplete="name"/>@endguest
                    <x-ui.field name="text" type="textarea" rows="5" placeholder="С чем к нам"/>
                    @guest
                        <label class="flex items-start gap-2.5 px-1 pt-1 text-sm text-ink-muted">
                            <span class="check mt-0.5"><input type="checkbox" name="consent" value="1" required></span>
                            <span>Даю <a href="/soglasie" class="text-accent-text hover:underline">согласие на обработку персональных данных</a> и принимаю <a href="/soglashenie" class="text-accent-text hover:underline">условия</a></span>
                        </label>
                        @error('consent')<p class="px-1 text-sm text-danger">{{ $message }}</p>@enderror
                    @endguest
                    <button type="submit" class="btn btn-accent w-full sm:w-auto sm:self-start sm:px-8">Отправить</button>
                </form>
            @endif
        </div>
    </section>
    @endunless
</x-ui.shell>
