import { Controller } from '@hotwired/stimulus';

// Файл наружу через системный лист (Файлы, AirDrop, Почта): в установленном
// приложении download открывает Quick Look без выхода, а target=_blank —
// встроенный браузер. Вешается на ссылку или на GET-форму (галки + кнопка
// формата). На компьютере лист не нужен — ссылка и форма работают как есть.
export default class extends Controller {
    static values = { name: String };

    connect() {
        this.canShare = !!navigator.canShare && matchMedia('(pointer: coarse)').matches
            && navigator.canShare({ files: [new File([''], 'a.txt', { type: 'text/plain' })] });
        if (!this.canShare) this.element.removeAttribute('data-action');
    }

    async share(event) {
        event.preventDefault();
        if (this.busy) return;
        const url = this.url(event.submitter);
        if (!url) return;
        this.busy = true;
        this.element.setAttribute('aria-busy', 'true');
        try {
            const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/octet-stream, application/json' } });
            if (!r.ok) throw new Error();
            const blob = await r.blob();
            const file = new File([blob], this.fileName(r), { type: blob.type || 'application/octet-stream' });
            if (navigator.canShare({ files: [file] })) await navigator.share({ files: [file] });
            else window.open(url, '_blank');
        } catch (e) {
            if (e?.name !== 'AbortError') window.toast?.('Не получилось', 'danger');
        } finally {
            this.busy = false;
            this.element.removeAttribute('aria-busy');
        }
    }

    // Ссылка — её href; форма — action с полями и нажатой кнопкой.
    url(submitter) {
        const el = this.element;
        if (el.tagName !== 'FORM') return el.href;
        const data = new FormData(el);
        if (submitter?.name) data.append(submitter.name, submitter.value);
        if (el.querySelector('input[type=checkbox]') && !el.querySelector('input[type=checkbox]:checked')) {
            window.toast?.('Выберите листы', 'danger');
            return null;
        }
        return el.action.split('?')[0] + '?' + new URLSearchParams(data);
    }

    // Имя из Content-Disposition (filename*=UTF-8''… или filename=…), иначе заданное.
    fileName(r) {
        const cd = r.headers.get('content-disposition') || '';
        const star = cd.match(/filename\*=UTF-8''([^;]+)/i);
        if (star) return decodeURIComponent(star[1]);
        const plain = cd.match(/filename="?([^";]+)"?/i);
        return plain ? plain[1] : this.nameValue || 'file';
    }
}
