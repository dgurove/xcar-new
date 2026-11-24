import { Controller } from '@hotwired/stimulus';
import { onMorphElement } from '../lib/morph-guard';

// «Эксклюзив за оценку» в шторке «Выставить в продажу» (24.11.2026, владелец: «при публикации делать выбор, кому из них
// эксклюзив»; `x-offer.publish-sheet`, сервер — `Exclusive::decideFrom`): кнопка неактивна, пока не отмечен кто-то из
// оценивших или «Никому»; «Никому» снимает людей, человек снимает «Никому». Срок — только когда кто-то отмечен. Рому сервер
// отмечает сам (`Exclusive::preferred`). Живой морф окна выбор человека не сбрасывает (галка, что он поменял, не морфится),
// а кнопку и срок после него выставляем заново — сервер рисует их по своей отметке.
export default class extends Controller {
    static targets = ['person', 'none', 'submit', 'days'];

    connect() {
        this.sync();
        this.offGuard = onMorphElement((e) => {
            const el = e.target;
            if (el instanceof HTMLInputElement && this.element.contains(el) && el.checked !== el.defaultChecked
                && (el.name === 'exclusive_to[]' || el.name === 'exclusive_none' || el.name === 'exclusive_days')) e.preventDefault();
        });
        this.element.addEventListener('turbo:morph-element', this.onMorph = () => {
            if (this.morphing) return;
            this.morphing = true;
            queueMicrotask(() => { this.morphing = false; if (this.element.isConnected) this.sync(); });
        });
    }

    disconnect() {
        this.offGuard?.();
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
    }

    person() {
        if (this.personTargets.some((p) => p.checked)) this.noneTarget.checked = false;
        this.sync();
    }

    none() {
        if (this.noneTarget.checked) this.personTargets.forEach((p) => { p.checked = false; });
        this.sync();
    }

    sync() {
        if (!this.hasNoneTarget) return;
        const someone = this.personTargets.some((p) => p.checked);
        const chosen = someone || this.noneTarget.checked;
        this.submitTargets.forEach((b) => { b.disabled = !chosen; });
        if (this.hasDaysTarget) this.daysTarget.hidden = !someone;
    }
}
