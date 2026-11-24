import { Controller } from '@hotwired/stimulus';
import { onMorphElement } from '../lib/morph-guard';

// «Эксклюзив за оценку» — выбор (`x-offer.exclusive-pick`, 24.11.2026, владелец выбрал А1): «Никому» снимает людей, человек
// снимает «Никому», срок — только когда кто-то отмечен. Блок помечает себя `data-decided`; кнопка вокруг
// (`[data-exclusive-gate]` → `[data-exclusive-submit]`, шторка «Выставить в продажу» одной машины и пачки) неактивна, пока
// хоть один видимый блок не решён. Пачке (`number`) блок виден, только пока галка её машины отмечена — проверяем, когда
// открывается шторка (галки ставит и «Выбрать все» без событий). «Показ» (`mode=show`) кнопку не держит: решение уходит,
// только если блок тронули (`apply`) — иначе «Сохранить» по другому поводу решал бы за человека (Рома отмечен сам).
// Живой морф выбор не сбрасывает: галка, что человек поменял, не морфится.
export default class extends Controller {
    static targets = ['person', 'none', 'days', 'apply'];
    static values = { mode: String, number: String };

    connect() {
        this.offGuard = onMorphElement((e) => {
            const el = e.target;
            if (el instanceof HTMLInputElement && this.element.contains(el) && el.name.startsWith('exclusive[')
                && (el.checked !== el.defaultChecked || (el.type === 'hidden' && el.value !== el.defaultValue))) e.preventDefault();
        });
        this.element.addEventListener('turbo:morph-element', this.onMorph = () => {
            if (this.morphing) return;
            this.morphing = true;
            queueMicrotask(() => { this.morphing = false; if (this.element.isConnected) this.sync(); });
        });
        this.dialog = this.element.closest('dialog');
        if (this.numberValue && this.dialog) {
            this.watch = new MutationObserver(() => { if (this.dialog.open) this.refresh(); });
            this.watch.observe(this.dialog, { attributes: true, attributeFilter: ['open'] });
            this.refresh();
        }
        // Форма ушла — решение записано, дальше правду говорит сервер.
        this.onSent = (e) => { if (e.detail?.success && this.hasApplyTarget && e.target === this.applyTarget.form) { this.touched = false; } };
        document.addEventListener('turbo:submit-end', this.onSent);
        this.sync();
    }

    disconnect() {
        document.removeEventListener('turbo:submit-end', this.onSent);
        this.offGuard?.();
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
        this.watch?.disconnect();
    }

    // Пачка: блок — только у отмеченной машины.
    refresh() {
        this.element.hidden = !document.getElementById(`pick-${this.numberValue}`)?.checked;
        this.sync();
    }

    person() {
        if (this.personTargets.some((p) => p.checked)) this.noneTarget.checked = false;
        this.touch();
    }

    none() {
        if (this.noneTarget.checked) this.personTargets.forEach((p) => { p.checked = false; });
        this.touch();
    }

    // Тронули — решение уходит с формой (`apply`); помним и после морфа: у скрытого поля морф ставит серверный 0.
    touch() {
        this.touched = true;
        this.sync();
    }

    sync() {
        if (!this.hasNoneTarget) return;
        if (this.hasApplyTarget) this.applyTarget.value = this.touched ? '1' : '0';
        const someone = this.personTargets.some((p) => p.checked);
        this.element.dataset.decided = someone || this.noneTarget.checked ? '1' : '0';
        if (this.hasDaysTarget) this.daysTarget.hidden = !someone;
        const gate = this.element.closest('[data-exclusive-gate]');
        if (!gate) return;
        const open = [...gate.querySelectorAll('[data-controller~="exclusive-pick"]')].some((b) => !b.hidden && b.dataset.decided !== '1');
        gate.querySelectorAll('[data-exclusive-submit]').forEach((b) => { b.disabled = open; });
    }
}
