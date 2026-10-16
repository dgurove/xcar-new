import { Controller } from '@hotwired/stimulus';
import { rub, rubles } from '../lib/money';

// Шторка «В продажу» на парковке (13.10.2026): оценочная правится, закупочная — по правилу от неё, равна ей или своя;
// суммы в строках выбора и кнопка «Выставить за …» пересчитываются на лету. Правило — зеркало `Park\Sale::floorFrom`,
// менять вместе.
export default class extends Controller {
    static targets = ['value', 'own', 'rule', 'same', 'button'];

    connect() {
        this.update();
    }

    update() {
        const value = rubles(this.valueTarget.value);
        const rule = value ? floorFrom(value) : null;
        this.ruleTarget.textContent = rule ? `${rub(rule)} (${markup(value)})` : 'нет оценочной';
        this.sameTarget.textContent = value ? rub(value) : 'нет оценочной';
        const mode = this.element.querySelector('input[name=floor_mode]:checked')?.value;
        const floor = mode === 'rule' ? rule : mode === 'value' ? value : rubles(this.ownTarget.value);
        this.buttonTarget.textContent = floor ? `Выставить за ${rub(floor)}` : 'Выставить в продажу';
    }
}

function floorFrom(value) {
    const price = value < 500000 ? value + 50000 : value < 2000000 ? value * 1.1 : value + 200000;
    return Math.ceil(Math.round(price * 100) / 100 / 1000) * 1000;
}

function markup(value) {
    return value < 500000 ? '+50 000 ₽' : value < 2000000 ? '+10%' : '+200 000 ₽';
}
