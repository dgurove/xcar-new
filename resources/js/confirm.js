import { openSheet, closeSheet } from './sheet';

// Подтверждение своей шторкой вместо window.confirm («crm.xcar.ru says»):
// вопрос заголовком, кнопка с глаголом («Удалить», «Отклонить», «Выдать») и
// «Отмена»; data-turbo-confirm-text у формы — абзац под вопросом (комментарий).
// Глагол — data-turbo-confirm-label у формы, иначе подпись нажатой
// кнопки, иначе первое слово вопроса. Опасное — btn-danger, если кнопка была
// опасной или вопрос начинается с «Удалить»/«Отклонить»/«Отменить»/«Снять».
// Turbo зовёт confirmSheet через Turbo.config.forms.confirm, контроллеры — сами.
export function confirmSheet(message, options = {}) {
    const { form, submitter } = options;
    const label = form?.dataset.turboConfirmLabel || shortText(submitter) || message.trim().split(/\s+/)[0].replace(/[?,.!]+$/, '');
    const danger = options.danger ?? (submitter?.matches?.('.btn-danger, .text-danger') || /^(удалить|отклонить|отменить|снять)/i.test(message));

    return new Promise((resolve) => {
        document.getElementById('confirm')?.remove();
        const d = document.createElement('dialog');
        d.id = 'confirm';
        d.className = 'sheet';
        d.dataset.turboTemporary = '';
        d.innerHTML = `
            <h2 class="mb-5 text-lg"></h2>
            <p class="-mt-3 mb-5 text-ink-muted" data-text hidden></p>
            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                <button type="button" class="btn btn-block sm:flex-1 ${danger ? 'btn-danger' : 'btn-accent'}" data-ok></button>
                <button type="button" class="btn btn-quiet btn-block sm:flex-1" data-cancel>Отмена</button>
            </div>`;
        d.querySelector('h2').textContent = message;
        d.querySelector('[data-ok]').textContent = label;
        const text = form?.dataset.turboConfirmText;
        if (text) { const p = d.querySelector('[data-text]'); p.textContent = text; p.hidden = false; }
        document.body.append(d);

        let answer = false;
        d.querySelector('[data-ok]').addEventListener('click', () => { answer = true; closeSheet(d); });
        d.querySelector('[data-cancel]').addEventListener('click', () => closeSheet(d));
        d.addEventListener('click', (e) => { if (e.target === d) closeSheet(d); });
        d.addEventListener('cancel', (e) => { e.preventDefault(); closeSheet(d); });
        d.addEventListener('close', () => { d.remove(); resolve(answer); });
        openSheet(d, { history: false });
        d.querySelector('[data-cancel]').focus();
    });
}

function shortText(el) {
    const text = el?.textContent?.replace(/\s+/g, ' ').trim();
    return text && text.length <= 24 ? text : '';
}
