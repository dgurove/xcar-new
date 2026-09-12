import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Живые обновления. Один EventSource на сеанс, темы — из <meta name="mercure-topics">.
// По каналу приходит только событие; за содержимым идём на сервер: он решает,
// что этому человеку показать.
//   card    {number} — плитка оффера в каталоге: заменить или добавить
//   refresh {paths}  — если открыта одна из страниц, перечитать её (morph)
//   toast   {message, href}
//   badges           — счётчики таб-бара
export default class extends Controller {
    connect() {
        this.topics = document.querySelector('meta[name="mercure-topics"]')?.content || '';
        this.hub = document.querySelector('meta[name="mercure-hub"]')?.content;
        this.onVisible = () => document.visibilityState === 'visible' && this.catchup();
        this.onInput = (e) => e.target.form && (e.target.form.dataset.dirty = '1');
        document.addEventListener('visibilitychange', this.onVisible);
        document.addEventListener('input', this.onInput);
        document.addEventListener('turbo:before-render', this.onRender = () => this.retopic());
        this.open();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisible);
        document.removeEventListener('input', this.onInput);
        document.removeEventListener('turbo:before-render', this.onRender);
        this.source?.close();
    }

    // После входа или выхода набор тем меняется — пересоздаём соединение.
    retopic() {
        const topics = document.querySelector('meta[name="mercure-topics"]')?.content || '';
        if (topics !== this.topics) {
            this.topics = topics;
            this.source?.close();
            this.open();
        }
    }

    open() {
        if (!this.hub || !this.topics || typeof EventSource === 'undefined') return;
        const url = new URL(this.hub, location.origin);
        this.topics.split(',').filter(Boolean).forEach((t) => url.searchParams.append('topic', t));
        this.source = new EventSource(url, { withCredentials: true });
        this.source.addEventListener('card', (e) => this.card(JSON.parse(e.data)));
        this.source.addEventListener('refresh', (e) => this.refresh(JSON.parse(e.data)));
        this.source.addEventListener('toast', (e) => this.toast(JSON.parse(e.data)));
        this.source.addEventListener('badges', () => this.badges());
        this.source.addEventListener('chat', (e) => document.dispatchEvent(new CustomEvent('live:chat', { detail: JSON.parse(e.data) })));
        this.source.onopen = () => { if (this.wasOpen) this.catchup(); this.wasOpen = true; };
    }

    catchup() {
        this.badges();
    }

    async card({ number }) {
        const present = !!document.getElementById(`offer-${number}`);
        const list = document.getElementById('catalog');
        if (!present && !list) return;
        const r = await fetch(`/offers/${number}/card?present=${present ? 1 : 0}&list=${list?.dataset.list || ''}`, { headers: { Accept: 'text/vnd.turbo-stream.html' } });
        if (!r.ok) return;
        const html = await r.text();
        // Новая плитка въезжает наверх, только если человек стоит в начале
        // списка: иначе он получит N новых под кнопкой, а не под пальцем.
        if (!present && html.includes('action="prepend"') && (window.scrollY > 200 || location.search)) {
            this.pending(number);
            return;
        }
        Turbo.renderStreamMessage(html);
        if (document.getElementById('catalog')?.children.length) document.getElementById('catalog-empty')?.remove();
    }

    pending() {
        let pill = document.getElementById('live-pending');
        if (!pill) {
            pill = document.createElement('button');
            pill.id = 'live-pending';
            pill.type = 'button';
            pill.className = 'btn btn-s btn-accent fixed left-1/2 z-40 -translate-x-1/2 shadow-(--shadow-drop)';
            pill.style.top = 'calc(var(--spacing-header) + env(safe-area-inset-top) + .75rem)';
            pill.dataset.count = '0';
            // Морф на месте с фильтрами и прокруткой, без слайда; новое — сверху.
            pill.addEventListener('click', () => { Turbo.visit(location.href, { action: 'replace' }); scrollTo({ top: 0, behavior: 'smooth' }); });
            document.body.appendChild(pill);
        }
        pill.dataset.count = String(Number(pill.dataset.count) + 1);
        pill.textContent = `Новых: ${pill.dataset.count} — показать`;
    }

    refresh({ paths }) {
        if (!paths?.includes(location.pathname)) return;
        // Человек что-то печатает — не дёргаем страницу под руками.
        if (document.querySelector('form[data-dirty]') || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        // Блок в потоке (sheet--inflow) открыт всегда — мешает только модальная шторка.
        if (document.querySelector('dialog:modal')) return;
        Turbo.renderStreamMessage('<turbo-stream action="refresh"></turbo-stream>');
    }

    toast({ message, href }) {
        window.toast?.(message, href ? { href } : undefined);
    }

    async badges() {
        if (!this.topics.includes('user/')) return;
        const r = await fetch('/live/badges', { headers: { Accept: 'text/vnd.turbo-stream.html' } });
        if (r.ok) Turbo.renderStreamMessage(await r.text());
    }
}
