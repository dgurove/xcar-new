import { Controller } from '@hotwired/stimulus';

// Ссылка наружу: «Скопировать» кладёт в буфер, «Отправить» — системный лист
// (только там, где он есть; иначе кнопка прячется).
export default class extends Controller {
    static targets = ['share'];
    static values = { text: String, title: String };

    connect() {
        if (this.hasShareTarget && !navigator.share) this.shareTarget.hidden = true;
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
            window.toast?.('Ссылка в буфере');
        } catch {
            window.toast?.('Не получилось скопировать — выделите и скопируйте руками', 'danger');
        }
    }

    async share() {
        try {
            await navigator.share({ title: this.titleValue || undefined, url: this.textValue });
        } catch (e) {
            if (e?.name !== 'AbortError') this.copy();
        }
    }

    select(event) {
        const range = document.createRange();
        range.selectNodeContents(event.currentTarget);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
}
