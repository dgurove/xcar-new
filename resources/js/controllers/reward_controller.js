import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';

// Вознаграждение менеджеру ползунком, суммой или процентом от разницы (16.11.2026, `x-deal.reward-split`): любое из трёх
// двигает два других. Ползунок — шагом 5 %, а заливка и подписи полосы — по точной сумме. В форму — скрытое поле в
// рублях: вознаграждение (`reward`) или услуги подбора = разница − вознаграждение (`ours`). Разница меняется на лету — сумма
// остаётся, процент пересчитывается; а если последним вписали процент (или поле предзаполнено долей, `follow`), — остаётся
// процент, и сумма идёт за разницей. Хозяину (`commission`, `reprice`) поле суммы шлёт `input` — его суммы считаются как
// раньше; пока шлём мы, `typed` процент не трогает (иначе «33,» превращалось бы в «33» под пальцем).
export default class extends Controller {
    static targets = ['range', 'rub', 'pct', 'hidden', 'mgr', 'ours', 'bar', 'margin', 'pctBox', 'rubLabel', 'row'];
    static values = { margin: Number, mode: { type: String, default: 'reward' }, follow: Boolean };

    connect() {
        this.byPct = this.followValue;
        if (this.byPct && this.split && this.reward === null && this.pctTarget.value !== '') return this.percent();
        this.paint();
    }

    get split() {
        return this.hasMarginValue && this.element.hasAttribute('data-reward-margin-value') && this.marginValue > 0;
    }

    get reward() {
        return rubles(this.rubTarget.value);
    }

    // Сумму вписали руками.
    typed() {
        if (this.quiet) return this.write();
        this.byPct = false;
        if (this.split) {
            const r = this.reward;
            this.pctTarget.value = r === null ? '' : this.percentText(r / this.marginValue * 100);
            this.rangeTarget.value = r === null ? 0 : this.snap(r / this.marginValue * 100);
        }
        this.paint();
    }

    percent() {
        const typed = this.pctTarget.value.replace(/[^\d,.]/g, '').replace('.', ',');
        // Одна запятая и десятые: «33,3».
        const [whole, frac] = typed.split(',');
        const clean = frac === undefined ? whole : `${whole},${frac.slice(0, 1)}`;
        if (clean !== this.pctTarget.value) this.pctTarget.value = clean;
        this.byPct = clean !== '';
        const p = clean === '' ? null : Number(clean.replace(',', '.'));
        this.rangeTarget.value = p === null ? 0 : this.snap(p);
        this.setRub(p === null ? null : Math.round(this.marginValue * p / 100));
    }

    slide() {
        const p = Number(this.rangeTarget.value);
        this.byPct = true;
        this.pctTarget.value = this.percentText(p);
        this.setRub(Math.round(this.marginValue * p / 100));
    }

    // Хозяин сменил разницу (цену или закупочную вписывают тут же).
    marginValueChanged() {
        if (!this.hasRubTarget) return;
        const split = this.split;
        this.barTarget.hidden = !split;
        this.pctBoxTarget.hidden = !split;
        this.marginTarget.hidden = !split || this.marginTarget.hasAttribute('data-off');
        this.rubLabelTarget.hidden = !split;
        this.rowTarget.classList.toggle('grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]', split);
        if (split) this.marginTarget.textContent = `разница ${nums(this.marginValue)}`;
        if (!split) return this.paint();
        const p = this.pctTarget.value === '' ? null : Number(this.pctTarget.value.replace(',', '.'));
        if (this.byPct && p !== null) {
            this.rangeTarget.value = this.snap(p);
            this.setRub(Math.round(this.marginValue * p / 100));
            return;
        }
        const r = this.reward;
        this.pctTarget.value = r === null ? '' : this.percentText(r / this.marginValue * 100);
        this.rangeTarget.value = r === null ? 0 : this.snap(r / this.marginValue * 100);
        this.paint();
    }

    setRub(r) {
        this.rubTarget.value = r === null ? '' : nums(r);
        this.quiet = true;
        this.rubTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.quiet = false;
        this.paint();
    }

    snap(p) {
        return Math.max(0, Math.min(100, Math.round(p / 5) * 5));
    }

    percentText(p) {
        return String(Math.round(p * 10) / 10).replace('.', ',');
    }

    // Скрытое поле в рублях: вознаграждение или услуги подбора. Без разницы — как вписано. Услуги подбора — со знаком: ноль —
    // их нет (19.11.2026, гаражная «себе» без подбора), минус — вознаграждение больше разницы, сервер отвечает `SHARE_LEFT`.
    write() {
        const r = this.reward;
        const ours = this.modeValue === 'ours' && this.split;
        this.hiddenTarget.value = r === null ? '' : (ours ? this.marginValue - r : r);
    }

    paint() {
        this.write();
        if (!this.split) return;
        const r = this.reward ?? 0;
        const rest = this.marginValue - r;
        this.barTarget.style.setProperty('--p', `${Math.max(0, Math.min(100, r / this.marginValue * 100))}%`);
        this.barTarget.classList.toggle('is-over', rest < 0);
        this.mgrTargets.forEach((el) => { el.textContent = `Менеджеру ${nums(r)}`; });
        this.oursTargets.forEach((el) => { el.textContent = `Подбор ${rest < 0 ? '−' : ''}${nums(Math.abs(rest))}`; });
    }
}
