import { toast } from './core.js';
import './customer-menu.js';
import './staff-live.js';

/**
 * Sheets (bottom sheet on mobile, centered dialog on desktop).
 */
function openSheet(name) {
    const root = document.querySelector(`[data-sheet="${name}"]`);

    if (!root || root.dataset.open === 'true') {
        return;
    }

    root.hidden = false;
    root.dataset.open = 'true';
    document.body.style.overflow = 'hidden';

    window.requestAnimationFrame(() => {
        root.querySelector('.sheet-backdrop')?.classList.add('is-open');
        root.querySelector('.sheet-panel')?.classList.add('is-open');
    });

    const focusTarget = root.querySelector('[autofocus], input:not([type="hidden"]), textarea, select, button, a[href]');
    window.setTimeout(() => focusTarget?.focus({ preventScroll: true }), 120);
}

function closeSheet(root) {
    if (!root) {
        return;
    }

    root.querySelector('.sheet-backdrop')?.classList.remove('is-open');
    root.querySelector('.sheet-panel')?.classList.remove('is-open');
    root.dataset.open = 'false';

    window.setTimeout(() => {
        if (root.dataset.open !== 'true') {
            root.hidden = true;
        }
    }, 320);

    if (!document.querySelector('[data-sheet][data-open="true"], [data-drawer][data-open="true"]')) {
        document.body.style.overflow = '';
    }
}

function openDrawer(name) {
    const root = document.querySelector(`[data-drawer="${name}"]`);

    if (!root || root.dataset.open === 'true') {
        return;
    }

    root.hidden = false;
    root.dataset.open = 'true';
    document.body.style.overflow = 'hidden';

    window.requestAnimationFrame(() => {
        root.querySelector('.drawer-panel')?.classList.add('is-open');
        root.querySelector('[data-drawer-backdrop]')?.classList.remove('hidden');
    });
}

function closeDrawer(root) {
    if (!root) {
        return;
    }

    root.querySelector('.drawer-panel')?.classList.remove('is-open');
    root.querySelector('[data-drawer-backdrop]')?.classList.add('hidden');
    root.dataset.open = 'false';

    window.setTimeout(() => {
        if (root.dataset.open !== 'true') {
            root.hidden = true;
        }
    }, 320);

    if (!document.querySelector('[data-sheet][data-open="true"], [data-drawer][data-open="true"]')) {
        document.body.style.overflow = '';
    }
}

document.addEventListener('click', (event) => {
    const sheetOpen = event.target.closest('[data-sheet-open]');
    if (sheetOpen) {
        event.preventDefault();
        openSheet(sheetOpen.dataset.sheetOpen);
        return;
    }

    const sheetClose = event.target.closest('[data-sheet-close]');
    if (sheetClose) {
        event.preventDefault();
        closeSheet(sheetClose.closest('[data-sheet]'));
        return;
    }

    const drawerOpen = event.target.closest('[data-drawer-open]');
    if (drawerOpen) {
        event.preventDefault();
        openDrawer(drawerOpen.dataset.drawerOpen);
        return;
    }

    const drawerClose = event.target.closest('[data-drawer-close]');
    if (drawerClose) {
        event.preventDefault();
        closeDrawer(drawerClose.closest('[data-drawer]'));
        return;
    }

    const panelToggle = event.target.closest('[data-panel-toggle]');
    if (panelToggle) {
        document.querySelector('[data-panel-sidebar]')?.classList.remove('-translate-x-full');
        document.querySelector('[data-panel-backdrop]')?.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        return;
    }

    const panelClose = event.target.closest('[data-panel-close], [data-panel-backdrop]');
    if (panelClose) {
        document.querySelector('[data-panel-sidebar]')?.classList.add('-translate-x-full');
        document.querySelector('[data-panel-backdrop]')?.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        return;
    }

    const copyButton = event.target.closest('[data-copy]');
    if (copyButton) {
        const value = copyButton.dataset.copy === 'target'
            ? document.querySelector(copyButton.dataset.copyTarget)?.textContent?.trim()
            : copyButton.dataset.copy;

        navigator.clipboard?.writeText(value ?? '').then(() => toast('Panoya kopyalandı.', 'success')).catch(() => {
            toast('Kopyalanamadı. Metni elle seçebilirsiniz.', 'error');
        });
        return;
    }

    const passwordToggle = event.target.closest('[data-password-toggle]');
    if (passwordToggle) {
        const input = passwordToggle.closest('[data-password-field]')?.querySelector('input');
        if (input) {
            input.type = input.type === 'password' ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', input.type === 'password' ? 'Şifreyi göster' : 'Şifreyi gizle');
        }
        return;
    }

    const printButton = event.target.closest('[data-print]');
    if (printButton) {
        event.preventDefault();
        window.print();
        return;
    }

    const addOptionRow = event.target.closest('[data-add-option-row]');
    if (addOptionRow) {
        const form = addOptionRow.closest('[data-options-form]');
        const list = form?.querySelector('[data-option-list]');
        const template = form?.querySelector('[data-option-row-template]');

        if (form && list && template) {
            const index = Number.parseInt(form.dataset.nextIndex ?? '0', 10);
            const html = template.innerHTML.replaceAll('__INDEX__', String(index));
            list.insertAdjacentHTML('beforeend', html);
            form.dataset.nextIndex = String(index + 1);
        }
        return;
    }

    const quantityButton = event.target.closest('[data-quantity-change]');
    if (quantityButton) {
        const target = document.querySelector(quantityButton.dataset.quantityTarget);
        if (target) {
            const delta = Number(quantityButton.dataset.quantityChange);
            target.value = String(Math.max(1, Math.min(99, Number(target.value || 1) + delta)));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    document.querySelectorAll('[data-sheet][data-open="true"]').forEach(closeSheet);
    document.querySelectorAll('[data-drawer][data-open="true"]').forEach(closeDrawer);

    document.querySelector('[data-panel-sidebar]')?.classList.add('-translate-x-full');
    document.querySelector('[data-panel-backdrop]')?.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
});

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-auto-submit]')) {
        event.target.closest('form')?.submit();
    }

    if (event.target.matches('[data-image-input]')) {
        const wrapper = event.target.closest('[data-image-field]') ?? document;
        const preview = wrapper.querySelector('[data-image-preview]');
        const file = event.target.files?.[0];

        if (preview && file) {
            preview.src = URL.createObjectURL(file);
            preview.classList.remove('hidden');
            wrapper.querySelector('[data-image-placeholder]')?.classList.add('hidden');
        }
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        return;
    }

    if (form.dataset.submitOnce !== undefined) {
        const submit = form.querySelector('[type="submit"]');

        if (submit) {
            window.setTimeout(() => {
                submit.disabled = true;
                submit.dataset.originalLabel ??= submit.innerHTML;
                submit.innerHTML = form.dataset.loadingLabel ?? 'İşleniyor…';
            }, 0);
        }
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.querySelector('[data-panel-sidebar]')?.classList.remove('-translate-x-full');
        document.querySelector('[data-panel-backdrop]')?.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    } else {
        document.querySelector('[data-panel-sidebar]')?.classList.add('-translate-x-full');
    }
});

// Keep the active sidebar item visible on shorter laptop viewports.
document.querySelector('[data-panel-sidebar] .nav-link-active')?.scrollIntoView({ block: 'nearest' });

// Keep old inline option forms working after a validation round-trip.
document.querySelectorAll('[data-options-form]').forEach((form) => {
    if (!form.dataset.nextIndex) {
        form.dataset.nextIndex = String(form.querySelectorAll('[data-option-list] > *').length);
    }
});

function initSplitBill(root) {
    const dueCents = Number(root.dataset.dueCents || 0);
    const peopleInput = root.querySelector('[data-split-people]');
    const shareOutput = root.querySelector('[data-split-share]');
    const lastOutput = root.querySelector('[data-split-last]');
    const status = root.querySelector('[data-split-status]');
    const amountInput = document.querySelector('[data-payment-amount]');
    const personInput = document.querySelector('[data-payment-person]');
    const allocationInput = document.querySelector('[data-payment-allocation]');
    const formatter = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const money = (cents) => `₺${formatter.format(cents / 100)}`;

    if (!peopleInput || !amountInput) {
        return;
    }

    const people = () => Math.min(20, Math.max(1, Number.parseInt(peopleInput.value, 10) || 1));
    const shareCents = () => Math.floor(dueCents / people());
    const lastCents = () => dueCents - shareCents() * (people() - 1);

    const update = () => {
        const count = people();
        peopleInput.value = String(count);
        shareOutput.textContent = money(shareCents());
        lastOutput.textContent = money(lastCents());
    };

    allocationInput?.addEventListener('change', () => {
        const selectedCents = Number(allocationInput.selectedOptions[0]?.dataset.dueCents || dueCents);
        amountInput.value = selectedCents > 0 ? (selectedCents / 100).toFixed(2) : '';
    });

    const apply = (cents, label) => {
        if (allocationInput) {
            allocationInput.value = '';
        }
        amountInput.value = (cents / 100).toFixed(2);
        personInput.value = label;
        amountInput.focus({ preventScroll: true });
        status.textContent = `${money(cents)} ödeme alanına aktarıldı.`;
    };

    root.querySelectorAll('[data-split-step]').forEach((button) => {
        button.addEventListener('click', () => {
            peopleInput.value = String(people() + Number(button.dataset.splitStep));
            update();
        });
    });

    peopleInput.addEventListener('input', update);
    peopleInput.addEventListener('blur', update);

    root.querySelectorAll('[data-split-apply]').forEach((button) => {
        button.addEventListener('click', () => {
            if (dueCents <= 0) {
                return;
            }

            const count = people();
            const mode = button.dataset.splitApply;

            if (mode === 'share') {
                apply(shareCents(), `1. kişi / ${count}`);
            } else if (mode === 'last') {
                apply(lastCents(), `${count}. kişi / ${count}`);
            } else if (mode === 'half') {
                apply(Math.floor(dueCents / 2), 'Yarım hesap');
            } else {
                apply(dueCents, 'Tam hesap');
            }
        });
    });

    update();
}

document.querySelectorAll('[data-split-bill]').forEach(initSplitBill);

Object.assign(window.skykite ?? {}, { openSheet, closeSheet, openDrawer, closeDrawer });
