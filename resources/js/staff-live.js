import { $, $$, apiFetch, chime, escapeHtml, money, startPolling, toast } from './core.js';

const statusMeta = {
    pending: { label: 'Alındı', badge: 'badge-pending', next: 'Kabul et', nextStatus: 'accepted' },
    accepted: { label: 'Hazırlanıyor', badge: 'badge-preparing', next: 'Hazır', nextStatus: 'ready' },
    ready: { label: 'Hazır', badge: 'badge-ready', next: 'Teslim edildi', nextStatus: 'served' },
};

const laneEmpty = {
    pending: 'Yeni sipariş beklenmiyor.',
    accepted: 'Mutfakta işlemde sipariş yok.',
    ready: 'Servis bekleyen sipariş yok.',
};

function errorMessage(error) {
    const errors = error?.payload?.errors;
    if (errors && typeof errors === 'object') {
        return Object.values(errors).flat()[0] ?? error.message;
    }

    return error?.payload?.message ?? error?.message ?? 'İşlem tamamlanamadı.';
}

function orderTime(order) {
    if (!order.created_at) {
        return '';
    }

    return new Date(order.created_at).toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
}

function itemsHtml(order, compact = false) {
    return order.items.map((item) => {
        const options = item.options.length
            ? `<p class="mt-0.5 pl-5 text-[11px] leading-4 text-muted">${item.options.map((option) => escapeHtml(option.name)).join(' · ')}</p>`
            : '';
        const note = item.note
            ? `<p class="mt-0.5 pl-5 text-[11px] font-semibold leading-4 text-terracotta">Not: ${escapeHtml(item.note)}</p>`
            : '';

        return `
            <li class="${compact ? 'flex items-start justify-between gap-3' : 'flex items-start justify-between gap-3'}">
                <div class="min-w-0">
                    <p class="${compact ? 'text-base font-semibold' : 'text-[13px] font-semibold leading-5'} text-ink">
                        <span class="mr-1.5 inline-grid size-6 place-items-center rounded-full bg-cream text-[11px] font-bold text-forest">${item.quantity}</span>
                        ${escapeHtml(item.name)}
                    </p>
                    ${options}
                    ${note}
                </div>
                <span class="shrink-0 text-xs font-semibold text-muted">${money(item.line_total_cents)}</span>
            </li>`;
    }).join('');
}

function boardOrderCard(order) {
    const meta = statusMeta[order.status] ?? statusMeta.pending;
    const note = order.note
        ? `<p class="mt-2 rounded-xl bg-amber-soft px-3 py-2 text-xs font-semibold leading-5 text-[#8a5a14]">Not: ${escapeHtml(order.note)}</p>`
        : '';
    const coupon = order.coupon_code
        ? `<span class="badge badge-paid">Kupon ${escapeHtml(order.coupon_code)}</span>`
        : '';

    return `
        <article class="rounded-2xl border border-line bg-paper p-3.5 shadow-soft">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="break-words font-display text-lg font-semibold leading-tight text-ink">${escapeHtml(order.table_name ?? 'Masa')}</p>
                    <p class="mt-0.5 text-[11px] font-semibold text-muted">#${escapeHtml(order.number)}</p>
                    <p class="text-[10px] font-medium text-muted">${orderTime(order)} · ${escapeHtml(order.created_label ?? '')}</p>
                </div>
                <span class="badge ${meta.badge}">${meta.label}</span>
            </div>
            <ul class="mt-3 space-y-1.5 border-y border-line py-2.5">${itemsHtml(order)}</ul>
            ${note}
            <div class="mt-3 flex items-center justify-between gap-2">
                <strong class="font-display text-lg text-ink">${money(order.total_cents)}</strong>
                <div class="flex items-center gap-1.5">
                    ${coupon}
                    <a href="${escapeHtml(order.url)}" class="text-[11px] font-bold text-forest underline decoration-forest/30 underline-offset-4 hover:text-ink">Adisyon</a>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="btn btn-primary btn-sm" data-order-action data-order="${order.id}" data-status="${meta.nextStatus}">
                    ${meta.next}
                </button>
                <button type="button" class="btn btn-secondary btn-sm text-danger" data-order-action data-order="${order.id}" data-status="cancelled">
                    İptal
                </button>
            </div>
        </article>`;
}

function kitchenOrderCard(order) {
    const meta = statusMeta[order.status] ?? statusMeta.pending;
    const note = order.note
        ? `<p class="mt-2 rounded-xl bg-amber/15 px-3 py-2 text-sm font-semibold leading-5 text-amber">Not: ${escapeHtml(order.note)}</p>`
        : '';
    const action = order.status === 'pending'
        ? `<button type="button" class="w-full rounded-xl bg-amber px-3 py-2.5 text-sm font-bold text-ink transition hover:bg-[#f0b45f]" data-order-action data-order="${order.id}" data-status="accepted">Siparişi kabul et</button>`
        : order.status === 'accepted'
            ? `<button type="button" class="w-full rounded-xl bg-success px-3 py-2.5 text-sm font-bold text-white transition hover:bg-forest" data-order-action data-order="${order.id}" data-status="ready">Hazır olarak işaretle</button>`
            : `<p class="rounded-xl border border-dashed border-white/15 px-3 py-2 text-center text-xs font-bold uppercase tracking-[.14em] text-cream/45">Garson alacak</p>`;

    return `
        <article class="rounded-2xl border border-white/10 bg-white/[.05] p-3.5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="break-words font-display text-2xl font-semibold leading-tight text-cream">${escapeHtml(order.table_name ?? 'Masa')}</p>
                    <p class="mt-0.5 text-[11px] font-bold text-cream/50">#${escapeHtml(order.number)}</p>
                    <p class="text-[10px] font-medium text-cream/40">${orderTime(order)} · ${escapeHtml(order.created_label ?? '')}</p>
                </div>
                <span class="badge ${meta.badge}">${meta.label}</span>
            </div>
            <ul class="mt-3 space-y-2 border-y border-white/10 py-3 [&_.text-ink]:text-cream [&_.text-muted]:text-cream/50 [&_.bg-cream]:bg-white/10 [&_.text-forest]:text-amber">
                ${itemsHtml(order, true)}
            </ul>
            ${note}
            <div class="mt-3">${action}</div>
        </article>`;
}

function tableCard(table, kitchen = false) {
    if (table.is_open) {
        return `
            <a href="${escapeHtml(table.session_url ?? '#')}" class="group rounded-2xl border border-forest/15 bg-sage/40 p-2.5 transition hover:border-forest/40">
                <p class="truncate text-xs font-bold text-forest">${escapeHtml(table.name)}</p>
                <p class="mt-0.5 text-[10px] font-semibold text-forest/70">${table.active_orders} aktif · ${money(table.due_cents)}</p>
            </a>`;
    }

    return `
        <div class="rounded-2xl border border-dashed border-line px-2.5 py-2.5">
            <p class="truncate text-xs font-bold text-muted">${escapeHtml(table.name)}</p>
            <p class="mt-0.5 text-[10px] font-semibold text-muted/60">Boş</p>
        </div>`;
}

function requestCard(request) {
    return `
        <div class="rounded-2xl border border-amber/40 bg-amber-soft p-3">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-sm font-bold text-[#8a5a14]">${escapeHtml(request.table ?? 'Masa')} · ${escapeHtml(request.type_label)}</p>
                    <p class="mt-0.5 text-[11px] font-semibold text-[#8a5a14]/70">${escapeHtml(request.created_label ?? '')}</p>
                </div>
                <button type="button" class="grid size-8 shrink-0 place-items-center rounded-full bg-[#8a5a14]/10 text-[#8a5a14] transition hover:bg-[#8a5a14]/20" data-resolve-request="${request.id}" aria-label="Çağrıyı kapat" title="Çağrıyı kapat">
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <button type="button" class="mt-2 w-full rounded-xl bg-paper px-3 py-1.5 text-xs font-bold text-[#8a5a14]" data-resolve-request="${request.id}">Görüldü</button>
        </div>`;
}

function renderBoard(root, payload) {
    const { summary } = payload;
    $$('[data-kpi]', root).forEach((element) => {
        const key = element.dataset.kpi;
        const value = summary[key];
        element.textContent = key === 'paid_today' ? value : String(value ?? 0);
    });

    $$('[data-lane-count]', root).forEach((element) => {
        element.textContent = String(summary[element.dataset.laneCount] ?? 0);
    });

    const requestsCount = root.querySelector('[data-request-count]');
    if (requestsCount) {
        requestsCount.textContent = String(payload.requests.length);
    }

    ['pending', 'accepted', 'ready'].forEach((lane) => {
        const body = root.querySelector(`[data-lane-body="${lane}"]`);
        if (!body) {
            return;
        }

        body.innerHTML = payload.orders.filter((order) => order.status === lane).map(boardOrderCard).join('');

        if (body.children.length === 0) {
            body.innerHTML = `<p class="rounded-2xl border border-dashed border-line px-4 py-8 text-center text-xs font-medium text-muted">${laneEmpty[lane]}</p>`;
        }
    });

    const requestsRoot = root.querySelector('[data-requests]');
    if (requestsRoot) {
        requestsRoot.innerHTML = payload.requests.length
            ? payload.requests.map(requestCard).join('')
            : '<p class="rounded-2xl border border-dashed border-line px-4 py-5 text-center text-xs font-medium text-muted">Açık çağrı yok.</p>';
    }

    const tablesRoot = root.querySelector('[data-tables]');
    if (tablesRoot) {
        tablesRoot.innerHTML = payload.tables.map((table) => tableCard(table)).join('');
    }
}

function renderKitchen(root, payload) {
    $$('[data-lane-count]', root).forEach((element) => {
        element.textContent = String(payload.summary[element.dataset.laneCount] ?? 0);
    });

    ['pending', 'accepted', 'ready'].forEach((lane) => {
        const body = root.querySelector(`[data-lane-body="${lane}"]`);
        if (!body) {
            return;
        }

        body.innerHTML = payload.orders.filter((order) => order.status === lane).map(kitchenOrderCard).join('');

        if (body.children.length === 0) {
            body.innerHTML = `<p class="rounded-2xl border border-dashed border-white/10 px-4 py-10 text-center text-xs font-semibold text-cream/35">${laneEmpty[lane]}</p>`;
        }
    });
}

function syncSoundButton(root) {
    const button = root.querySelector('[data-sound-toggle]');
    if (!button) {
        return;
    }

    const off = localStorage.getItem('skykite.sound') === 'off';
    button.setAttribute('aria-pressed', off ? 'false' : 'true');
    const label = button.querySelector('[data-sound-label]');
    if (label) {
        label.textContent = off ? 'Ses kapalı' : 'Ses açık';
    }
}

function initBoard(root) {
    const endpoint = root.dataset.endpoint;
    const statusUrl = root.dataset.statusUrl;
    const resolveUrl = root.dataset.resolveUrl;
    const dialog = document.querySelector('[data-cancel-dialog]');
    let previousPending = null;

    const refresh = async () => {
        const payload = await apiFetch(endpoint);

        if (previousPending !== null && payload.summary.pending > previousPending) {
            chime();
            toast(`${payload.summary.pending - previousPending} yeni sipariş geldi.`, 'success');
        }
        previousPending = payload.summary.pending;

        renderBoard(root, payload);
        const updated = root.querySelector('[data-updated]');
        if (updated) {
            updated.textContent = new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    };

    const postStatus = async (orderId, status, reason = null) => {
        await apiFetch(statusUrl.replace('__ID__', orderId), {
            method: 'POST',
            body: { status, reason },
        });
        await refresh();
    };

    const openCancel = (orderId) => {
        if (!dialog) {
            const reason = window.prompt('İptal gerekçesi');
            if (reason) {
                postStatus(orderId, 'cancelled', reason).catch((error) => toast(errorMessage(error), 'error'));
            }
            return;
        }

        dialog.hidden = false;
        dialog.dataset.order = orderId;
        const reason = dialog.querySelector('textarea[name="reason"]');
        if (reason) {
            reason.value = '';
            window.setTimeout(() => reason.focus(), 80);
        }
    };

    const closeCancel = () => {
        if (dialog) {
            dialog.hidden = true;
            delete dialog.dataset.order;
        }
    };

    root.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-order-action]');
        if (action) {
            const orderId = action.dataset.order;
            const status = action.dataset.status;

            if (status === 'cancelled') {
                openCancel(orderId);
                return;
            }

            action.disabled = true;
            try {
                await postStatus(orderId, status);
                toast('Sipariş güncellendi.', 'success');
            } catch (error) {
                toast(errorMessage(error), 'error');
                action.disabled = false;
            }
            return;
        }

        const resolve = event.target.closest('[data-resolve-request]');
        if (resolve) {
            try {
                await apiFetch(resolveUrl.replace('__ID__', resolve.dataset.resolveRequest), { method: 'POST' });
                toast('Çağrı kapatıldı.', 'success');
                await refresh();
            } catch (error) {
                toast(errorMessage(error), 'error');
            }
            return;
        }

        if (event.target.closest('[data-board-refresh]')) {
            refresh().then(() => toast('Pano yenilendi.', 'success')).catch((error) => toast(errorMessage(error), 'error'));
        }
    });

    root.querySelector('[data-sound-toggle]')?.addEventListener('click', () => {
        const off = localStorage.getItem('skykite.sound') === 'off';
        localStorage.setItem('skykite.sound', off ? 'on' : 'off');
        syncSoundButton(root);
        toast(off ? 'Ses açıldı.' : 'Ses kapatıldı.', 'success');
    });

    dialog?.addEventListener('click', (event) => {
        if (event.target.closest('[data-cancel-close]')) {
            closeCancel();
        }
    });

    dialog?.querySelector('[data-cancel-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const reason = new FormData(event.target).get('reason')?.toString() ?? '';
        const orderId = dialog.dataset.order;
        const submit = event.target.querySelector('[type="submit"]');

        if (submit) {
            submit.disabled = true;
        }

        try {
            await postStatus(orderId, 'cancelled', reason);
            closeCancel();
            toast('Sipariş iptal edildi.', 'success');
        } catch (error) {
            toast(errorMessage(error), 'error');
        } finally {
            if (submit) {
                submit.disabled = false;
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            closeCancel();
        }
    });

    syncSoundButton(root);
    startPolling(refresh, 5000);
}

function initKitchen(root) {
    const endpoint = root.dataset.endpoint;
    const statusUrl = root.dataset.statusUrl;
    let previousPending = null;

    const refresh = async () => {
        const payload = await apiFetch(endpoint);

        if (previousPending !== null && payload.summary.pending > previousPending) {
            chime();
            toast(`${payload.summary.pending - previousPending} yeni sipariş geldi.`, 'success');
        }
        previousPending = payload.summary.pending;

        renderKitchen(root, payload);
        const updated = root.querySelector('[data-updated]');
        if (updated) {
            updated.textContent = new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    };

    root.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-order-action]');
        if (!action) {
            return;
        }

        action.disabled = true;
        try {
            await apiFetch(statusUrl.replace('__ID__', action.dataset.order), {
                method: 'POST',
                body: { status: action.dataset.status },
            });
            toast(action.dataset.status === 'ready' ? 'Sipariş hazır.' : 'Sipariş kabul edildi.', 'success');
            await refresh();
        } catch (error) {
            toast(errorMessage(error), 'error');
            action.disabled = false;
        }
    });

    root.querySelector('[data-sound-toggle]')?.addEventListener('click', () => {
        const off = localStorage.getItem('skykite.sound') === 'off';
        localStorage.setItem('skykite.sound', off ? 'on' : 'off');
        syncSoundButton(root);
        toast(off ? 'Ses açıldı.' : 'Ses kapatıldı.', 'success');
    });

    root.querySelector('[data-kitchen-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen?.();
        } else {
            document.documentElement.requestFullscreen?.();
        }
    });

    const clock = root.querySelector('[data-kitchen-clock]');
    const tick = () => {
        if (clock) {
            clock.textContent = new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        }
    };
    tick();
    window.setInterval(tick, 15000);

    syncSoundButton(root);
    startPolling(refresh, 5000);
}

const board = $('[data-staff-board]');
if (board) {
    initBoard(board);
}

const kitchen = $('[data-kitchen-board]');
if (kitchen) {
    initKitchen(kitchen);
}
