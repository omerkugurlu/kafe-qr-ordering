const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export const $ = (selector, scope = document) => scope.querySelector(selector);
export const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

export function money(cents) {
    return `₺${new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format((Number(cents) || 0) / 100)}`;
}

export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export function toast(message, type = 'info', timeout = 4200) {
    const root = document.getElementById('toast-root');

    if (!root || !message) {
        return;
    }

    const element = document.createElement('div');
    element.className = type === 'error' ? 'toast-error' : type === 'success' ? 'toast-success' : 'toast';
    element.setAttribute('role', 'status');

    const dot = document.createElement('span');
    dot.className = 'mt-1.5 size-2.5 shrink-0 rounded-full bg-current opacity-70';

    const text = document.createElement('p');
    text.className = 'flex-1 leading-snug';
    text.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'grid size-6 shrink-0 place-items-center rounded-full text-current/60 transition hover:bg-current/10 hover:text-current';
    close.setAttribute('aria-label', 'Bildirimi kapat');
    close.innerHTML = '<svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    close.addEventListener('click', () => element.remove());

    element.append(dot, text, close);
    root.append(element);

    window.setTimeout(() => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(6px)';
        element.style.transition = 'opacity 200ms ease, transform 200ms ease';
        window.setTimeout(() => element.remove(), 220);
    }, timeout);
}

export async function apiFetch(url, options = {}) {
    const method = options.method ?? 'GET';
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken,
        ...(options.headers ?? {}),
    };

    const request = { method, headers };

    if (options.body !== undefined && options.body !== null) {
        if (options.body instanceof FormData) {
            request.body = options.body;
        } else {
            headers['Content-Type'] = 'application/json';
            request.body = JSON.stringify(options.body);
        }
    }

    const response = await fetch(url, request);
    let payload = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok) {
        const error = new Error(payload?.message ?? 'İstek tamamlanamadı. Lütfen tekrar deneyin.');
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

export function startPolling(callback, interval = 5000, { immediate = true } = {}) {
    let timer = null;
    let stopped = false;
    let running = false;

    const tick = async () => {
        if (stopped || document.hidden || running) {
            return;
        }

        running = true;

        try {
            await callback();
        } catch (error) {
            console.warn('Polling failed', error);
        } finally {
            running = false;
        }
    };

    if (immediate) {
        tick();
    }

    timer = window.setInterval(tick, interval);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
        }
    });

    return {
        stop() {
            stopped = true;
            window.clearInterval(timer);
        },
        refresh: tick,
    };
}

let audioContext = null;

export function chime() {
    if (localStorage.getItem('skykite.sound') === 'off') {
        return;
    }

    try {
        audioContext ??= new (window.AudioContext || window.webkitAudioContext)();

        if (audioContext.state === 'suspended') {
            audioContext.resume();
        }

        const start = audioContext.currentTime;

        [880, 1174.66].forEach((frequency, index) => {
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start + index * 0.16);
            gain.gain.exponentialRampToValueAtTime(0.16, start + index * 0.16 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + index * 0.16 + 0.32);
            oscillator.connect(gain).connect(audioContext.destination);
            oscillator.start(start + index * 0.16);
            oscillator.stop(start + index * 0.16 + 0.34);
        });
    } catch {
        // Audio is a progressive enhancement.
    }
}

window.skykite = { toast, apiFetch, money, startPolling, chime, escapeHtml };
