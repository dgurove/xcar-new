import { Controller } from '@hotwired/stimulus';

// Файл наружу с телефона. В установленном приложении download открывает Quick
// Look без выхода, а лист navigator.share Safari даёт только внутри жеста —
// пока файл собирается на сервере, жест истекает. Поэтому на тач-устройстве
// файл открывается во встроенном браузере (window.open в самом жесте): там
// предпросмотр с «Поделиться» и кнопка «Готово». Вешается на ссылку или на
// GET-форму (галки + кнопка формата). На компьютере — обычное скачивание.
export default class extends Controller {
    connect() {
        if (!matchMedia('(pointer: coarse)').matches) this.element.removeAttribute('data-action');
    }

    share(event) {
        event.preventDefault();
        const url = this.url(event.submitter);
        if (!url) return;
        const inline = new URL(url, location.href);
        inline.searchParams.set('inline', '1');
        if (!window.open(inline.toString(), '_blank')) window.toast?.('Не получилось', 'danger');
    }

    // Ссылка — её href; форма — action с полями и нажатой кнопкой.
    url(submitter) {
        const el = this.element;
        if (el.tagName !== 'FORM') return el.href;
        const data = new FormData(el);
        if (submitter?.name) data.append(submitter.name, submitter.value);
        if (el.querySelector('input[type=checkbox]') && !el.querySelector('input[type=checkbox]:checked')) {
            window.toast?.('Выберите, что выгружать', 'danger');
            return null;
        }
        return el.action.split('?')[0] + '?' + new URLSearchParams(data);
    }
}
