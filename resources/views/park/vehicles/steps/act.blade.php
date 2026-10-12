{{-- Текущий шаг дела с действием — кнопка окна действия (`x-park.act-window`, 12.10.2026): приём, письмо вендору, выдача.
     Самой формы в линии нет: машина — страницей, действие — окном. --}}
<div class="mt-3"><x-park.act-button :vehicle="$vehicle" :step="$step->plate['step']" :label="$step->plate['label']" class="w-full sm:w-auto"/></div>
