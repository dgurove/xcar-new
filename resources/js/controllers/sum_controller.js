import { Controller } from '@hotwired/stimulus';
import { rub } from '../lib/money';

// Кнопка списка с галочками говорит, сколько выбрано и на сколько: «Выставить 3 счета на 2 900 ₽».
// Строки — чекбоксы с data-amount, слова — data-sum-words-value: «счёт|счета|счетов». Строки с одним data-group —
// один счёт (реестр плательщику в закрытии месяца): считаются один раз.
export default class extends Controller {
    static targets = ['box', 'label'];
    static values = { verb: String, words: String };

    connect() { this.update(); }

    update() {
        const on = this.boxTargets.filter((b) => b.checked && !b.disabled);
        const n = new Set(on.map((b, i) => b.dataset.group || `row-${i}`)).size;
        const sum = on.reduce((s, b) => s + Number(b.dataset.amount || 0), 0);
        const words = this.wordsValue.split('|');
        const word = n % 10 === 1 && n % 100 !== 11 ? words[0] : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? words[1] : words[2]);
        this.labelTarget.textContent = n ? `${this.verbValue} ${n} ${word} на ${rub(sum)}` : this.verbValue;
        this.labelTarget.closest('button')?.toggleAttribute('disabled', n === 0);
    }
}
