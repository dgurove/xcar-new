{{-- На телефоне «Добавить покупателя» — плашкой внизу экрана; на компьютере хватает строки в списке. --}}
<div class="contents md:hidden">
    <x-ui.action-bar>
        <x-people.pick name="buyer" kind="buyer" only reload link trigger="button" placeholder="Добавить покупателя"/>
    </x-ui.action-bar>
</div>
