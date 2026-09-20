@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Отчёт вендору»: кнопка открывает окно писем с готовым черновиком (акт и фото приложены). --}}<div class="mt-3"><x-mail.window-button :url="$step->plate['url']" label="Отправить" class="btn-accent"/></div>
