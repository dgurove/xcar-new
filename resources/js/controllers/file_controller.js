import { Controller } from '@hotwired/stimulus';
import { shareFile, warm } from '../docs/share';

// Файл наружу с телефона — лист «Поделиться» с самим файлом (docs/share.js): в установленном приложении download
// открывает Quick Look без выхода, а встроенный браузер уводил из приложения. Вешается на ссылку или на GET-форму
// (галки + кнопка формата). Ссылка греет файл при касании — лист открывается в том же жесте; форма собирает файл
// по галкам, её файл качается по нажатию. На компьютере — обычное скачивание.
export default class extends Controller {
    connect() {
        if (!matchMedia('(pointer: coarse)').matches) { this.element.removeAttribute('data-action'); return; }
        if (this.element.tagName === 'A') {
            this.onDown = () => warm(this.element.href, this.name()).catch(() => {});
            this.element.addEventListener('pointerdown', this.onDown, { passive: true });
        }
    }

    disconnect() {
        if (this.onDown) this.element.removeEventListener('pointerdown', this.onDown);
    }

    share(event) {
        event.preventDefault();
        const url = this.url(event.submitter);
        if (url) shareFile(url, this.name());
    }

    // Подпись файла, если сервер не назвал его сам.
    name() {
        const el = this.element;
        return el.dataset.fileName || el.dataset.docName || el.title || (el.tagName === 'A' ? el.textContent.trim() : '') || 'Файл';
    }

    // Ссылка — её href; форма — action с полями и нажатой кнопкой.
    url(submitter) {
        const el = this.element;
        if (el.tagName !== 'FORM') return el.href;
        const data = new FormData(el);
        if (submitter?.name) data.append(submitter.name, submitter.value);
        // Кнопке с data-file-any галки не нужны (упрощённая выгрузка — ДЛ и наша цена).
        if (!submitter?.hasAttribute('data-file-any') && el.querySelector('input[type=checkbox]') && !el.querySelector('input[type=checkbox]:checked')) {
            window.toast?.('Выберите, что выгружать', 'danger');
            return null;
        }
        // У кнопки может быть свой formaction (акт сверки и Excel из одной формы).
        return (submitter?.formAction || el.action).split('?')[0] + '?' + new URLSearchParams(data);
    }
}
