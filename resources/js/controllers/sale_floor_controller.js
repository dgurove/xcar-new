import { Controller } from '@hotwired/stimulus';
import { floorFrom, markup } from '../lib/floor';
import { rub, rubles } from '../lib/money';

// Шторка «В продажу» на парковке (13.10.2026): оценочная правится, закупочная — по правилу от неё, равна ей или своя;
// суммы в строках выбора и кнопка «Выставить за …» пересчитываются на лету. Правило — `lib/floor.js` со ступенями сервера.
export default class extends Controller {
    static targets = ['value', 'own', 'rule', 'same', 'button'];
    static values = { rules: Array };

    connect() {
        this.update();
    }

    update() {
        const value = rubles(this.valueTarget.value);
        const rule = value ? floorFrom(value, this.rulesValue) : null;
        this.ruleTarget.textContent = rule ? `${rub(rule)} (${markup(value, this.rulesValue, rub)})` : 'нет оценочной';
        this.sameTarget.textContent = value ? rub(value) : 'нет оценочной';
        const mode = this.element.querySelector('input[name=floor_mode]:checked')?.value;
        const floor = mode === 'rule' ? rule : mode === 'value' ? value : rubles(this.ownTarget.value);
        this.buttonTarget.textContent = floor ? `Выставить за ${rub(floor)}` : 'Выставить в продажу';
    }
}
