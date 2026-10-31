import { Controller } from '@hotwired/stimulus';
import { Viewport } from '../park/plan/viewport';

// Схема парковки (`x-park.yard-plan`). full — страница схемы: нажали машину (на схеме или в лотке «без места») — она
// выбрана, внизу плашка с ней, свободные места светятся; нажали свободное — перестановка (`Move`), занятое — обмен
// (`SwapSpots`, с подтверждением), мимо или её же — отмена. Мышью машину можно перетащить на место. Поиск по госномеру,
// VIN, убытку — подсветка и подъезд к машине. pick — выбор места в шторке: свободное место пишется в поле «Место» формы.
// Схему перерисовывает морф (Mercure) — вид и выбор остаются.
export default class extends Controller {
    static targets = ['svg', 'stage', 'bar', 'barIcon', 'barTitle', 'barFacts', 'barLink', 'move', 'swap', 'tray', 'find'];
    static values = { mode: { type: String, default: 'full' }, yard: Number, cars: Object, vehicle: Number };

    connect() {
        this.view = new Viewport(this.svgTarget, { onTap: (e) => this.tap(e), grab: (e) => this.grab(e), min: this.modeValue === 'pick' ? 4 : 5 });
        this.onMorph = () => this.restore();
        document.addEventListener('turbo:morph', this.onMorph);
        this.onKey = (e) => { if (e.key === 'Escape' && this.selected) this.deselect(); };
        addEventListener('keydown', this.onKey);
        if (this.modeValue === 'pick') {
            const scope = this.element.closest('[data-controller~="spots"]') || this.element.closest('form');
            this.field = scope?.querySelector('input[name="spot"], input[name="stages[spot]"]');
            this.onType = () => this.mark(this.field.value.trim().toUpperCase());
            this.mark(this.field?.value.trim().toUpperCase() || this.svgTarget.querySelector('.is-current')?.dataset.spot);
            this.field?.addEventListener('input', this.onType);
        }
        this.restore(true);
    }

    disconnect() {
        this.view?.detach();
        document.removeEventListener('turbo:morph', this.onMorph);
        removeEventListener('keydown', this.onKey);
        this.field?.removeEventListener('input', this.onType);
    }

    // После морфа: тот же вид и та же выбранная машина (если она ещё здесь), отмеченное место — к нему.
    restore(first = false) {
        this.view.attach(this.svgTarget);
        if (this.selected && !this.carsValue[this.selected]) this.selected = null;
        this.paint();
        if (first) {
            const current = this.svgTarget.querySelector('.is-current, .is-picked');
            if (current) requestAnimationFrame(() => this.view.focus(current.getBBox(), 24));
        }
    }

    zoomIn() { this.view.zoom(1.6); }
    zoomOut() { this.view.zoom(1 / 1.6); }
    fit() { this.view.fit(); }

    // Мышь на машине — перетаскивание (пальцем — сдвиг схемы, машину выбирают нажатием).
    grab(e) {
        if (this.modeValue !== 'full' || e.pointerType !== 'mouse' || e.type !== 'pointerdown') return false;
        const spot = e.target.closest?.('[data-vehicle]');
        if (!spot) return false;
        this.drag = { id: spot.dataset.vehicle, x: e.clientX, y: e.clientY, spot, moved: false };
        const move = (ev) => this.dragMove(ev);
        const up = (ev) => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); this.dragEnd(ev); };
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
        return true;
    }

    dragMove(e) {
        const d = this.drag;
        if (!d.moved && Math.hypot(e.clientX - d.x, e.clientY - d.y) < 5) return;
        if (!d.moved) {
            d.moved = true;
            this.select(d.id);
            // «Призрак» — значок той же машины под курсором; поворот значка в узком месте — в его transform.
            const car = d.spot.querySelector('.plan-car');
            d.box = car.getBBox();
            d.ghost = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            d.ghost.classList.add('spot-ghost');
            d.ghost.append(car.cloneNode(true));
            this.svgTarget.append(d.ghost);
            this.svgTarget.classList.add('is-dragging');
        }
        const p = this.view.point(e);
        d.ghost.setAttribute('transform', `translate(${p.x - (d.box.x + d.box.width / 2)} ${p.y - (d.box.y + d.box.height / 2)})`);
        const over = document.elementFromPoint(e.clientX, e.clientY)?.closest?.('[data-spot]');
        if (over !== d.over) { d.over?.classList.remove('is-over'); over?.classList.add('is-over'); d.over = over; }
    }

    dragEnd(e) {
        const d = this.drag;
        this.drag = null;
        d.ghost?.remove();
        d.over?.classList.remove('is-over');
        this.svgTarget.classList.remove('is-dragging');
        if (!d.moved) { this.tapSpot(d.spot); return; }
        const target = document.elementFromPoint(e.clientX, e.clientY)?.closest?.('[data-spot]');
        if (target && target !== d.spot && this.svgTarget.contains(target)) this.tapSpot(target);
    }

    tap(e) {
        this.tapSpot(e.target.closest?.('[data-spot]'));
    }

    tapSpot(spot) {
        if (this.modeValue === 'pick') {
            if (spot && (!spot.dataset.vehicle || Number(spot.dataset.vehicle) === this.vehicleValue)) this.pick(spot.dataset.spot);
            return;
        }
        if (this.modeValue !== 'full') return;
        const id = spot?.dataset.vehicle;
        if (!this.selected) { if (id) this.select(id); return; }
        if (!spot || id === this.selected) { this.deselect(); return; }
        if (!id) this.moveTo(spot.dataset.spot);
        else this.swapWith(id);
    }

    // Машина из лотка «без места».
    take(e) {
        const id = e.currentTarget.dataset.vehicle;
        if (id === this.selected) this.deselect();
        else this.select(id);
    }

    select(id) {
        this.selected = String(id);
        this.paint();
        const car = this.carsValue[this.selected];
        if (!car || !this.hasBarTarget) return;
        this.barIconTarget.innerHTML = car.icon;
        this.barTitleTarget.textContent = car.title;
        // Госномер — плашкой `x-ui.plate` (разметка с сервера), место — словами.
        const where = Object.assign(document.createElement('span'), { textContent: car.spot ? `место ${car.spot}` : 'без места' });
        this.barFactsTarget.innerHTML = car.plate;
        this.barFactsTarget.append(where);
        this.barLinkTarget.href = `/cars/${this.selected}`;
        this.barTarget.hidden = false;
        this.stageTarget.classList.add('has-bar');
    }

    deselect() {
        this.selected = null;
        this.paint();
        if (this.hasBarTarget) this.barTarget.hidden = true;
        if (this.hasStageTarget) this.stageTarget.classList.remove('has-bar');
    }

    paint() {
        const svg = this.svgTarget;
        svg.classList.toggle('is-lifting', !!this.selected);
        svg.querySelectorAll('.is-picked').forEach((n) => n.classList.remove('is-picked'));
        this.trayTargets.forEach((n) => n.classList.toggle('is-picked', n.dataset.vehicle === this.selected));
        if (this.selected) svg.querySelector(`[data-vehicle="${this.selected}"]`)?.classList.add('is-picked');
        if (this.query) this.search();
    }

    moveTo(spot) {
        const form = this.moveTarget;
        form.action = `/cars/${this.selected}/move`;
        form.elements.spot.value = spot;
        form.requestSubmit();
        this.deselect();
    }

    swapWith(id) {
        const a = this.carsValue[this.selected];
        const b = this.carsValue[id];
        const form = this.swapTarget;
        form.action = `/cars/${this.selected}/swap`;
        form.elements.with.value = id;
        form.dataset.turboConfirm = `Поменять местами ${a?.short ?? 'ТС'} (${a?.spot ?? 'без места'}) и ${b?.short ?? 'ТС'} (${b?.spot})?`;
        form.requestSubmit();
        this.deselect();
    }

    // Поиск: подходящие машины подсвечены, к первой подъезжаем.
    search() {
        this.query = this.hasFindTarget ? this.findTarget.value.trim().toLowerCase() : '';
        const q = this.query.replace(/\s+/g, '');
        const hits = [];
        this.svgTarget.querySelectorAll('[data-find]').forEach((n) => {
            const hit = q.length >= 2 && n.dataset.find.replace(/\s+/g, '').includes(q);
            n.classList.toggle('is-found', hit);
            if (hit) hits.push(n);
        });
        this.svgTarget.classList.toggle('is-finding', q.length >= 2);
        this.trayTargets.forEach((n) => n.classList.toggle('is-found', q.length >= 2 && (n.dataset.find || '').replace(/\s+/g, '').includes(q)));
        return hits;
    }

    find() {
        const hits = this.search();
        if (hits.length) this.view.focus(hits[0].getBBox(), 30);
    }

    // pick: место в поле формы и отметка на схеме.
    pick(code) {
        if (!this.field) return;
        this.field.value = code;
        this.field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    mark(code) {
        this.svgTarget.querySelectorAll('.is-picked').forEach((n) => n.classList.remove('is-picked'));
        if (code) this.svgTarget.querySelector(`[data-spot="${CSS.escape(code)}"]`)?.classList.add('is-picked');
    }
}
