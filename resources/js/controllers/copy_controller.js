import { Controller } from '@hotwired/stimulus';
import { copyText } from '../lib/clipboard';

// Ссылка наружу: «Скопировать» кладёт в буфер, «Отправить» — системный лист
// (только там, где он есть; иначе кнопка прячется). Если рядом поле с сообщением,
// ссылка уходит вместе с ним одним текстом: url отдельно от text мессенджеры
// на Android теряют, а внутри текста ссылка остаётся кликабельной везде.
// done — свой текст тоста (VIN, номер), по умолчанию про ссылку. Цель field — копируется то, что сейчас в поле.
export default class extends Controller {
    static targets = ['share', 'message', 'field', 'button'];
    static values = { text: String, title: String, done: String };

    connect() {
        // Системного листа нет (ПК) — «Отправить» прячется, главной становится кнопка с data-copy-fallback.
        if (this.hasShareTarget && !navigator.share) {
            this.shareTarget.hidden = true;
            this.element.querySelector('[data-copy-fallback]')?.classList.replace('btn-quiet', 'btn-accent');
        }
        this.sync();
        // Перечитка страницы морфом возвращает кнопке вид с сервера, контроллер при этом не переподключается.
        this.onMorph = () => this.sync();
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
    }

    // Копировать нечего — кнопки нет: у пустого поля зелёная иконка только сбивает.
    sync() {
        if (this.hasFieldTarget && this.hasButtonTarget) this.buttonTarget.hidden = this.fieldTarget.value.trim() === '';
    }

    payload() {
        if (this.hasFieldTarget) return this.fieldTarget.value.trim();
        const message = this.hasMessageTarget ? this.messageTarget.value.trim() : '';
        return message ? `${message}\n${this.textValue}` : this.textValue;
    }

    copy() {
        return copyText(this.payload(), this.doneValue || (this.hasMessageTarget ? 'Текст и ссылка в буфере' : 'Ссылка в буфере'));
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
