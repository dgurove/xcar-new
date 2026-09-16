import { Controller } from '@hotwired/stimulus';

// Ссылка наружу: «Скопировать» кладёт в буфер, «Отправить» — системный лист
// (только там, где он есть; иначе кнопка прячется). Если рядом поле с сообщением,
// ссылка уходит вместе с ним одним текстом: url отдельно от text мессенджеры
// на Android теряют, а внутри текста ссылка остаётся кликабельной везде.
export default class extends Controller {
    static targets = ['share', 'message'];
    static values = { text: String, title: String };

    connect() {
        if (this.hasShareTarget && !navigator.share) this.shareTarget.hidden = true;
    }

    payload() {
        const message = this.hasMessageTarget ? this.messageTarget.value.trim() : '';
        return message ? `${message}\n${this.textValue}` : this.textValue;
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.payload());
            window.toast?.(this.hasMessageTarget ? 'Текст и ссылка в буфере' : 'Ссылка в буфере');
        } catch {
            window.toast?.('Не получилось скопировать — выделите и скопируйте руками', 'danger');
        }
    }

    async share() {
        try {
            await navigator.share(this.hasMessageTarget
                ? { title: this.titleValue || undefined, text: this.payload() }
                : { title: this.titleValue || undefined, url: this.textValue });
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
