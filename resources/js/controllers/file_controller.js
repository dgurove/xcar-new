import { Controller } from '@hotwired/stimulus';

// Файл наружу через системный лист (Файлы, AirDrop, Почта): в установленном
// приложении download ненадёжен, а target=_blank открывает встроенный браузер.
// Где листа с файлами нет (десктоп) — кнопка остаётся обычной ссылкой download.
export default class extends Controller {
    static values = { name: String };

    connect() {
        this.canShare = !!navigator.canShare && navigator.canShare({ files: [new File([''], 'a.txt', { type: 'text/plain' })] });
        if (!this.canShare) this.element.removeAttribute('data-action');
    }

    async share(event) {
        event.preventDefault();
        if (this.busy) return;
        this.busy = true;
        this.element.setAttribute('aria-busy', 'true');
        try {
            const r = await fetch(this.element.href, { credentials: 'same-origin' });
            if (!r.ok) throw new Error();
            const blob = await r.blob();
            const file = new File([blob], this.nameValue || 'file', { type: blob.type || 'application/octet-stream' });
            if (navigator.canShare({ files: [file] })) await navigator.share({ files: [file] });
            else window.open(this.element.href, '_blank');
        } catch (e) {
            if (e?.name !== 'AbortError') window.toast?.('Не получилось', 'danger');
        } finally {
            this.busy = false;
            this.element.removeAttribute('aria-busy');
        }
    }
}
