import { Controller } from '@hotwired/stimulus';

// Свернуть группу таблицы нажатием на её заголовок (`tr.table-group`, 12.10.2026, владелец: «сворачивать парковки»).
// Строки до следующего заголовка прячутся (`hidden`: ↑/↓ карточки их пропускают), заголовок с числом остаётся.
// Память — в браузере по ключу группы (`data-fold-key`), живой морф списка не сбрасывает: после него свёртка ставится снова.
export default class extends Controller {
    static targets = ['head'];
    static values = { name: String };

    connect() {
        this.apply();
        this.onMorph = () => this.apply();
        document.addEventListener('turbo:morph', this.onMorph);
        document.addEventListener('turbo:frame-render', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
        document.removeEventListener('turbo:frame-render', this.onMorph);
    }

    headTargetConnected() {
        this.apply();
    }

    toggle(event) {
        const head = event.currentTarget;
        const folded = this.folded();
        const key = head.dataset.foldKey;
        folded.has(key) ? folded.delete(key) : folded.add(key);
        try { localStorage.setItem(this.nameValue, JSON.stringify([...folded])); } catch {}
        this.apply();
    }

    folded() {
        try { return new Set(JSON.parse(localStorage.getItem(this.nameValue) || '[]')); } catch { return new Set(); }
    }

    apply() {
        const folded = this.folded();
        for (const head of this.headTargets) {
            const shut = folded.has(head.dataset.foldKey);
            head.classList.toggle('is-folded', shut);
            for (let row = head.nextElementSibling; row && !row.classList.contains('table-group'); row = row.nextElementSibling) {
                row.hidden = shut;
            }
        }
    }
}
