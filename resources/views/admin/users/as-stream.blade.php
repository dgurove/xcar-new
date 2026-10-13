{{-- Ответ «Войти как»: шторка со ссылкой сразу, без подтверждения, редиректа и перерисовки страницы (13.10.2026: через
     back() и flash ссылка терялась — popstate шторки подтверждения, чужой запрос сессии, окошко у списка). Повторное
     нажатие заменяет прежнюю шторку. --}}
<turbo-stream action="remove" target="as-sheet"></turbo-stream>
<turbo-stream action="append" targets="body"><template>
    <div id="as-sheet" data-controller="sheet" class="contents" data-turbo-temporary>
        <x-ui.sheet id="as-dialog" :title="'Вход как '.$user->shortName()" open>
            <x-ui.copy-link :url="$url" :title="'Вход как '.$user->shortName()">
                <p class="text-sm text-ink-muted">Откройте в окне инкогнито, иначе здесь вы выйдете</p>
            </x-ui.copy-link>
        </x-ui.sheet>
    </div>
</template></turbo-stream>
