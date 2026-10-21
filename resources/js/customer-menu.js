import { money, toast, apiFetch, startPolling, escapeHtml } from './core.js';

const coffeeFallback = '<svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v2M10 2v2M14 2v2"/></svg>';

function readJson(id) {
    const element = document.getElementById(id);

    if (!element) {
        return null;
    }

    try {
        return JSON.parse(element.textContent);
    } catch {
        return null;
    }
}

function statusBadgeClass(status) {
    return {
        pending: 'badge-pending',
        accepted: 'badge-preparing',
        ready: 'badge-ready',
        served: 'badge-served',
        paid: 'badge-paid',
        cancelled: 'badge-cancelled',
    }[status] ?? 'badge-neutral';
}

function updateBillRequestAvailability(session) {
    const available = session?.can_request_bill === true;

    document.querySelectorAll('[data-bill-request]').forEach((button) => {
        button.dataset.billRequestAvailable = String(available);
        const coolingDown = button.dataset.billRequestCoolingDown === 'true';
        const disabled = !available || coolingDown;
        button.disabled = disabled;
        button.setAttribute('aria-disabled', String(disabled));

        const group = button.closest('[data-bill-request-group]');
        const hint = group?.querySelector('[data-bill-request-hint]') ?? button.querySelector('[data-bill-request-hint]');

        if (hint) {
            hint.hidden = available;

            if (available) {
                button.removeAttribute('aria-describedby');
            } else if (hint.id) {
                button.setAttribute('aria-describedby', hint.id);
            }
        }
    });
}

/* -------------------------------------------------------------------------
 * Customer menu
 * ---------------------------------------------------------------------- */

const menuRoot = document.getElementById('customer-menu');
const menuData = readJson('menu-data');

if (menuRoot && menuData) {
    initMenu(menuRoot, menuData);
}

function initMenu(root, data) {
    const strings = data.strings;
    const products = new Map(data.products.map((product) => [Number(product.id), product]));
    const categories = data.categories;
    const optionById = new Map();

    products.forEach((product) => {
        product.option_groups.forEach((group) => {
            group.options.forEach((option) => {
                optionById.set(Number(option.id), { ...option, group, productId: Number(product.id) });
            });
        });
    });

    const productsById = (id) => products.get(Number(id));
    const cartKey = `skykite.cart.${data.table.token}`;
    const noteKey = `skykite.note.${data.table.token}`;
    const storedCart = loadCart();
    let cart = storedCart;
    let orderNote = localStorage.getItem(noteKey) ?? '';
    let coupon = null;
    let loyaltyCampaignId = null;
    const loyalty = data.loyalty ?? null;
    let activeCategory = 'all';
    let search = '';
    let sheetState = null;
    let upsellState = null;
    let lastStatuses = new Map();
    let statusesInitialised = false;
    let couponTimer = null;

    document.querySelector('[data-featured-prev]')?.addEventListener('click', () => scrollFeatured(-1));
    document.querySelector('[data-featured-next]')?.addEventListener('click', () => scrollFeatured(1));
    document.getElementById('featured-products')?.addEventListener('scroll', updateFeaturedControls, { passive: true });
    window.addEventListener('resize', updateFeaturedControls, { passive: true });

    const openSheet = (name) => window.skykite?.openSheet?.(name);
    const openDrawer = (name) => window.skykite?.openDrawer?.(name);
    const closeSheet = (name) => {
        const element = document.querySelector(`[data-sheet="${name}"]`);

        if (element) {
            window.skykite?.closeSheet?.(element);
        }
    };
    const closeDrawer = (name) => {
        const element = document.querySelector(`[data-drawer="${name}"]`);

        if (element) {
            window.skykite?.closeDrawer?.(element);
        }
    };

    function loadCart() {
        try {
            const raw = JSON.parse(localStorage.getItem(cartKey) ?? '[]');

            if (!Array.isArray(raw)) {
                return [];
            }

            return raw
                .map((line) => {
                    const product = productsById(line.productId);

                    if (!product) {
                        return null;
                    }

                    const optionIds = Array.isArray(line.optionIds)
                        ? [...new Set(line.optionIds.map(Number))].filter((id) => {
                            const option = optionById.get(id);

                            return option && option.productId === Number(product.id);
                        })
                        : [];

                    return {
                        productId: Number(product.id),
                        quantity: Math.max(1, Math.min(99, Number(line.quantity) || 1)),
                        optionIds,
                        note: typeof line.note === 'string' ? line.note.slice(0, 140) : '',
                    };
                })
                .filter(Boolean);
        } catch {
            return [];
        }
    }

    function persistCart() {
        try {
            localStorage.setItem(cartKey, JSON.stringify(cart));
        } catch {
            // Storage can be unavailable in private mode.
        }
    }

    function persistNote() {
        try {
            localStorage.setItem(noteKey, orderNote);
        } catch {
            // Storage can be unavailable in private mode.
        }
    }

    function lineKey(line) {
        return `${line.productId}:${[...line.optionIds].sort((a, b) => a - b).join(',')}:${(line.note ?? '').trim()}`;
    }

    function optionDelta(optionIds) {
        return optionIds.reduce((sum, id) => sum + (optionById.get(Number(id))?.price_delta_cents ?? 0), 0);
    }

    function lineUnit(line) {
        const product = productsById(line.productId);
        return (product?.price_cents ?? 0) + optionDelta(line.optionIds);
    }

    function lineTotal(line) {
        return lineUnit(line) * line.quantity;
    }

    function cartSubtotal() {
        return cart.reduce((sum, line) => sum + lineTotal(line), 0);
    }

    function effectiveDiscount() {
        if (coupon) {
            if (coupon.min_subtotal_cents !== null && coupon.min_subtotal_cents !== undefined && cartSubtotal() < coupon.min_subtotal_cents) {
                return 0;
            }

            return Math.min(coupon.discount_cents ?? 0, cartSubtotal());
        }

        return loyaltyDiscount();
    }

    function activeLoyaltyCampaign() {
        return loyalty?.campaigns.find((campaign) => Number(campaign.id) === Number(loyaltyCampaignId)) ?? null;
    }

    function loyaltyDiscount() {
        const campaign = activeLoyaltyCampaign();

        if (!campaign) {
            return 0;
        }

        const subtotal = cartSubtotal();

        if (subtotal < campaign.minimum_order_cents) {
            return 0;
        }

        const reward = campaign.reward_type === 'percent'
            ? Math.round(subtotal * (campaign.reward_value_cents / 10000))
            : campaign.reward_value_cents;

        return Math.min(subtotal, Math.max(0, reward));
    }

    function cartTotal() {
        return Math.max(0, cartSubtotal() - effectiveDiscount());
    }

    function addLine(productId, quantity = 1, optionIds = [], note = '') {
        const product = productsById(productId);

        if (!product) {
            return;
        }

        const normalized = {
            productId: Number(product.id),
            quantity: Math.max(1, Math.min(99, quantity)),
            optionIds: [...new Set(optionIds.map(Number))].sort((a, b) => a - b),
            note: String(note ?? '').slice(0, 140).trim(),
        };
        const key = lineKey(normalized);
        const existing = cart.find((line) => lineKey(line) === key);

        if (existing) {
            existing.quantity = Math.min(99, existing.quantity + normalized.quantity);
        } else {
            cart.push(normalized);
        }

        persistCart();
        renderCart();
        scheduleCouponRevalidation();
    }

    function removeLine(key) {
        cart = cart.filter((line) => lineKey(line) !== key);
        persistCart();
        renderCart();
        scheduleCouponRevalidation();
    }

    function changeLineQuantity(key, delta) {
        const line = cart.find((item) => lineKey(item) === key);

        if (!line) {
            return;
        }

        line.quantity += delta;

        if (line.quantity <= 0) {
            removeLine(key);
            return;
        }

        line.quantity = Math.min(99, line.quantity);
        persistCart();
        renderCart();
        scheduleCouponRevalidation();
    }

    function productImage(product, sizeClass, iconClass = 'size-7') {
        if (product.image_url) {
            return `<img src="${escapeHtml(product.image_url)}" alt="" class="${sizeClass} object-cover" loading="lazy">`;
        }

        return `<span class="grid ${sizeClass} place-items-center bg-gradient-to-br from-sage to-cream text-forest/50">${coffeeFallback.replace('size-7', iconClass)}</span>`;
    }

    function productCard(product) {
        const unavailable = !product.is_available;
        const optionsHint = product.option_groups.length > 0
            ? `<span class="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">${escapeHtml(strings.customize)}</span>`
            : '';

        return `
            <article class="group relative overflow-hidden rounded-3xl border border-line bg-paper shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-forest/25 hover:shadow-soft ${unavailable ? 'opacity-60' : ''}">
                <button type="button" class="absolute inset-0 z-0 size-full" data-product-open="${product.id}" aria-label="${escapeHtml(product.name)}" ${unavailable ? 'disabled' : ''}></button>
                <div class="pointer-events-none relative z-10 flex gap-4 p-4">
                    <div class="relative size-24 shrink-0 overflow-hidden rounded-2xl bg-cream">
                        ${productImage(product, 'size-24', 'size-7')}
                        ${product.prep_minutes ? `<span class="absolute bottom-1.5 left-1.5 rounded-full bg-ink/75 px-2 py-0.5 text-[9px] font-bold text-white">${product.prep_minutes} ${escapeHtml(strings.min)}</span>` : ''}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-2">
                            <h3 class="min-w-0 flex-1 font-display text-base font-semibold leading-snug text-ink">${escapeHtml(product.name)}</h3>
                            ${unavailable ? `<span class="badge-neutral shrink-0">${escapeHtml(strings.sold_out)}</span>` : ''}
                        </div>
                        ${product.description ? `<p class="mt-1 line-clamp-2 text-xs leading-relaxed text-muted">${escapeHtml(product.description)}</p>` : ''}
                        <div class="mt-3 flex items-center justify-between gap-3 pr-9">
                            <span class="text-sm font-bold text-forest">${escapeHtml(product.price_label)}</span>
                            ${optionsHint}
                        </div>
                    </div>
                </div>
                ${unavailable ? '' : `<button type="button" data-quick-add="${product.id}" class="absolute bottom-3 right-3 z-20 grid size-9 place-items-center rounded-full bg-forest text-white shadow-soft transition hover:bg-ink" aria-label="${escapeHtml(product.name)} — ${escapeHtml(strings.add)}"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button>`}
            </article>
        `;
    }

    function featuredCard(product) {
        return `
            <article class="featured-product-card group relative snap-start overflow-hidden rounded-3xl border border-line bg-paper shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-soft">
                <button type="button" class="absolute inset-0 z-0 size-full" data-product-open="${product.id}" aria-label="${escapeHtml(product.name)}"></button>
                <div class="relative h-36 overflow-hidden bg-cream">
                    ${productImage(product, 'h-36 w-full', 'size-10')}
                    <div class="absolute inset-0 bg-gradient-to-t from-ink/55 via-ink/5 to-transparent"></div>
                    ${product.prep_minutes ? `<span class="absolute right-3 top-3 rounded-full bg-paper/90 px-2.5 py-1 text-[10px] font-bold text-ink">${product.prep_minutes} ${escapeHtml(strings.min)}</span>` : ''}
                </div>
                <div class="pointer-events-none relative z-10 p-4">
                    <h3 class="font-display text-base font-semibold text-ink">${escapeHtml(product.name)}</h3>
                    <div class="mt-1 flex items-center justify-between gap-3 pr-9">
                        <span class="text-sm font-bold text-forest">${escapeHtml(product.price_label)}</span>
                        ${product.is_available ? '' : `<span class="badge-neutral">${escapeHtml(strings.sold_out)}</span>`}
                    </div>
                </div>
                ${product.is_available ? `<button type="button" data-quick-add="${product.id}" class="absolute bottom-3 right-3 z-20 grid size-9 place-items-center rounded-full bg-forest text-white shadow-soft transition hover:bg-ink" aria-label="${escapeHtml(product.name)} — ${escapeHtml(strings.add)}"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button>` : ''}
            </article>
        `;
    }

    function updateFeaturedControls() {
        const root = document.getElementById('featured-products');
        const controls = document.querySelector('[data-featured-controls]');
        const previous = document.querySelector('[data-featured-prev]');
        const next = document.querySelector('[data-featured-next]');

        if (!root || !controls || !previous || !next) {
            return;
        }

        const canScroll = root.scrollWidth > root.clientWidth + 1;
        controls.hidden = !canScroll;
        previous.disabled = root.scrollLeft <= 1;
        next.disabled = root.scrollLeft + root.clientWidth >= root.scrollWidth - 1;
    }

    function scrollFeatured(direction) {
        const root = document.getElementById('featured-products');
        const card = root?.querySelector('.featured-product-card');

        if (!root || !card) {
            return;
        }

        const gap = Number.parseFloat(window.getComputedStyle(root).columnGap) || 0;
        root.scrollBy({ left: direction * (card.getBoundingClientRect().width + gap), behavior: 'smooth' });
    }

    function renderChips() {
        const root = document.getElementById('category-chips');

        if (!root) {
            return;
        }

        const all = [{ id: 'all', name: strings.all }, ...categories.map((category) => ({ id: String(category.id), name: category.name }))];
        root.innerHTML = all.map((category) => `
            <button type="button" data-category="${category.id}" class="${activeCategory === category.id ? 'chip-active' : 'chip'}" aria-pressed="${activeCategory === category.id}">
                ${escapeHtml(category.name)}
            </button>
        `).join('');
    }

    function renderFeatured() {
        const root = document.getElementById('featured-products');
        const section = document.getElementById('featured-section');

        if (!root || !section) {
            return;
        }

        const items = data.featured_ids
            .map((id) => productsById(id))
            .filter((product) => product && product.is_available);

        if (items.length === 0 || activeCategory !== 'all' || search.trim() !== '') {
            section.hidden = true;
            root.innerHTML = '';
            updateFeaturedControls();
            return;
        }

        section.hidden = false;
        root.innerHTML = items.map(featuredCard).join('');
        window.requestAnimationFrame(updateFeaturedControls);
    }

    function renderSections() {
        const root = document.getElementById('menu-sections');

        if (!root) {
            return;
        }

        if (search.trim() !== '') {
            root.hidden = true;
            return;
        }

        root.hidden = false;
        const visible = activeCategory === 'all'
            ? categories
            : categories.filter((category) => String(category.id) === activeCategory);

        root.innerHTML = visible.map((category) => {
            const items = category.product_ids
                .map((id) => productsById(id))
                .filter(Boolean);

            if (items.length === 0) {
                return '';
            }

            return `
                <section id="category-${category.id}" class="scroll-mt-36">
                    <div class="mb-3 flex items-end justify-between gap-3">
                        <div>
                            <h2 class="font-display text-xl font-semibold tracking-tight text-ink sm:text-2xl">${escapeHtml(category.name)}</h2>
                            ${category.description ? `<p class="mt-0.5 text-xs text-muted">${escapeHtml(category.description)}</p>` : ''}
                        </div>
                        <span class="shrink-0 text-[11px] font-bold text-muted">${items.length} ${escapeHtml(strings.items_count.replace(':count', '').trim())}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">${items.map(productCard).join('')}</div>
                </section>
            `;
        }).join('');

        if (root.innerHTML.trim() === '') {
            root.innerHTML = `<div class="rounded-3xl border border-dashed border-line bg-paper/60 px-6 py-12 text-center text-sm text-muted">${escapeHtml(strings.empty_category)}</div>`;
        }
    }

    function renderSearch() {
        const root = document.getElementById('search-results');
        const empty = document.getElementById('menu-empty');

        if (!root || !empty) {
            return;
        }

        const term = search.trim().toLocaleLowerCase('tr');

        if (term === '') {
            root.hidden = true;
            root.innerHTML = '';
            empty.hidden = true;
            return;
        }

        const results = [...products.values()].filter((product) => {
            const haystack = [product.name, product.description, product.category_name].filter(Boolean).join(' ').toLocaleLowerCase('tr');
            const matchesCategory = activeCategory === 'all' || String(product.category_id) === activeCategory;

            return matchesCategory && haystack.includes(term);
        });

        root.hidden = false;
        empty.hidden = results.length > 0;
        root.innerHTML = results.map(productCard).join('');
    }

    function renderContent() {
        renderFeatured();
        renderSections();
        renderSearch();
    }

    function openProduct(id) {
        const product = productsById(id);

        if (!product) {
            return;
        }

        if (!product.is_available) {
            toast(strings.sold_out, 'error');
            return;
        }

        const optionIds = new Set();

        product.option_groups.forEach((group) => {
            if (group.selection_mode === 'single' && group.min_selections > 0 && group.options.length > 0) {
                optionIds.add(Number(group.options[0].id));
            }
        });

        sheetState = { product, qty: 1, optionIds, note: '' };
        renderProductSheet();
        openSheet('product');
    }

    function renderProductSheet() {
        const body = document.getElementById('product-sheet-body');

        if (!body || !sheetState) {
            return;
        }

        const product = sheetState.product;
        const unit = product.price_cents + optionDelta([...sheetState.optionIds]);

        const groups = product.option_groups
            .filter((group) => group.options.length > 0)
            .map((group) => {
                const single = group.selection_mode === 'single';
                const required = group.min_selections > 0;
                const max = single ? 1 : (group.max_selections ?? group.options.length);
                const helper = single
                    ? (required ? strings.required : strings.optional)
                    : `${group.min_selections}–${max} ${strings.choose.toLocaleLowerCase('tr')}`;

                return `
                    <fieldset class="mt-5" data-option-group-id="${group.id}">
                        <legend class="flex w-full items-center justify-between gap-3 text-sm font-bold text-ink">
                            <span>${escapeHtml(group.name)}</span>
                            <span class="text-[10px] font-bold uppercase tracking-[.12em] ${required ? 'text-terracotta' : 'text-muted'}">${escapeHtml(helper)}</span>
                        </legend>
                        <div class="mt-2 space-y-2">
                            ${group.options.map((option) => {
                                const checked = sheetState.optionIds.has(Number(option.id));

                                return `
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-line bg-paper px-3.5 py-3 transition hover:border-forest/25 has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                                        <input type="${single ? 'radio' : 'checkbox'}" name="group-${group.id}" value="${option.id}" data-option-choice="${option.id}" ${checked ? 'checked' : ''} class="size-4 accent-forest">
                                        <span class="min-w-0 flex-1 text-sm font-semibold text-ink">${escapeHtml(option.name)}</span>
                                        ${option.price_delta_cents > 0 ? `<span class="shrink-0 text-xs font-bold text-forest">${escapeHtml(option.price_delta_label)}</span>` : ''}
                                    </label>
                                `;
                            }).join('')}
                        </div>
                    </fieldset>
                `;
            }).join('');

        body.innerHTML = `
            <div class="relative">
                <div class="relative h-44 overflow-hidden bg-cream sm:h-52">
                    ${productImage(product, 'h-44 w-full sm:h-52', 'size-12')}
                    <div class="absolute inset-0 bg-gradient-to-t from-ink/55 via-transparent to-ink/10"></div>
                    <button type="button" class="absolute right-4 top-4 grid size-10 place-items-center rounded-full bg-paper/90 text-ink shadow-soft backdrop-blur transition hover:bg-paper" data-sheet-close aria-label="${escapeHtml(strings.close)}"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                    ${product.prep_minutes ? `<span class="absolute bottom-4 left-4 inline-flex items-center gap-1.5 rounded-full bg-paper/90 px-3 py-1.5 text-[11px] font-bold text-ink backdrop-blur"><svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>${product.prep_minutes} ${escapeHtml(strings.min)}</span>` : ''}
                </div>
                <div class="p-5 pb-8">
                    <p class="eyebrow">${escapeHtml(product.category_name ?? strings.menu)}</p>
                    <h2 id="product-sheet-title" class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink">${escapeHtml(product.name)}</h2>
                    ${product.description ? `<p class="mt-2 text-sm leading-relaxed text-muted">${escapeHtml(product.description)}</p>` : ''}
                    ${groups}
                    <div class="mt-5">
                        <label for="item-note" class="label">${escapeHtml(strings.per_item)}</label>
                        <textarea id="item-note" rows="2" maxlength="140" class="input resize-none" data-item-note placeholder="${escapeHtml(strings.item_note_placeholder)}">${escapeHtml(sheetState.note)}</textarea>
                    </div>
                    <div class="mt-5 flex items-center gap-3">
                        <div class="flex items-center gap-1 rounded-full border border-line bg-cream p-1">
                            <button type="button" class="grid size-9 place-items-center rounded-full text-ink transition hover:bg-paper" data-product-qty="-1" aria-label="${escapeHtml(strings.remove)}"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 12h14"/></svg></button>
                            <span class="min-w-8 text-center text-sm font-bold" data-product-qty-value>${sheetState.qty}</span>
                            <button type="button" class="grid size-9 place-items-center rounded-full text-ink transition hover:bg-paper" data-product-qty="1" aria-label="${escapeHtml(strings.add)}"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></button>
                        </div>
                        <p class="text-xs font-semibold text-muted"><span data-sheet-unit>${money(unit)}</span> / ${escapeHtml(strings.quantity.toLocaleLowerCase('tr'))}</p>
                    </div>
                    <p id="product-sheet-error" class="mt-3 hidden rounded-xl bg-danger-soft px-3 py-2 text-xs font-bold text-danger"></p>
                    <div class="mt-5 flex items-center gap-3 rounded-2xl bg-cream px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-muted">${escapeHtml(strings.total)}</p>
                            <p class="font-display text-xl font-semibold text-ink" data-sheet-total>${money(unit * sheetState.qty)}</p>
                        </div>
                        <button type="button" class="btn-primary shrink-0" data-add-to-cart>
                            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            ${escapeHtml(strings.add_to_cart)}
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    function refreshSheetPrice() {
        if (!sheetState) {
            return;
        }

        const unit = sheetState.product.price_cents + optionDelta([...sheetState.optionIds]);
        const unitNode = document.querySelector('[data-sheet-unit]');
        const totalNode = document.querySelector('[data-sheet-total]');
        const qtyNode = document.querySelector('[data-product-qty-value]');

        if (unitNode) {
            unitNode.textContent = money(unit);
        }

        if (totalNode) {
            totalNode.textContent = money(unit * sheetState.qty);
        }

        if (qtyNode) {
            qtyNode.textContent = String(sheetState.qty);
        }
    }

    function showSheetError(message) {
        const error = document.getElementById('product-sheet-error');

        if (!error) {
            return;
        }

        error.textContent = message;
        error.classList.remove('hidden');
    }

    function validateSheetSelections() {
        if (!sheetState) {
            return false;
        }

        for (const group of sheetState.product.option_groups) {
            if (group.options.length === 0) {
                continue;
            }

            const selected = group.options.filter((option) => sheetState.optionIds.has(Number(option.id))).length;
            const max = group.selection_mode === 'single' ? 1 : (group.max_selections ?? group.options.length);

            if (selected < group.min_selections) {
                showSheetError(`${group.name}: ${strings.choose} (${group.min_selections})`);
                document.querySelector(`[data-option-group-id="${group.id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            if (selected > max) {
                showSheetError(`${group.name}: en fazla ${max} seçim.`);
                return false;
            }
        }

        return true;
    }

    function addFromSheet() {
        if (!sheetState || !validateSheetSelections()) {
            return;
        }

        const product = sheetState.product;
        addLine(product.id, sheetState.qty, [...sheetState.optionIds], sheetState.note);
        toast(`${product.name} · ${strings.cart_added}`, 'success');
        closeSheet('product');

        if (product.suggestions.length > 0) {
            window.setTimeout(() => openUpsell(product), 220);
        }
    }

    function openUpsell(product) {
        const suggestions = product.suggestions
            .map((id) => productsById(id))
            .filter(Boolean);

        if (suggestions.length === 0) {
            return;
        }

        upsellState = { product, suggestions, added: new Set() };
        renderUpsell();
        openSheet('upsell');
    }

    function renderUpsell() {
        const body = document.getElementById('upsell-body');
        const title = document.getElementById('upsell-title');

        if (!body || !upsellState) {
            return;
        }

        if (title) {
            title.textContent = strings.suggestions_title;
        }

        body.innerHTML = upsellState.suggestions.map((product) => {
            const added = upsellState.added.has(Number(product.id));
            const needsOptions = product.option_groups.some((group) => group.min_selections > 0);

            return `
                <div class="flex items-center gap-3 rounded-2xl border border-line bg-paper p-2.5">
                    <div class="size-16 shrink-0 overflow-hidden rounded-xl bg-cream">${productImage(product, 'size-16', 'size-6')}</div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold text-ink">${escapeHtml(product.name)}</p>
                        <p class="mt-0.5 text-xs font-semibold text-forest">${escapeHtml(product.price_label)}</p>
                    </div>
                    ${added
                        ? `<span class="badge-ready shrink-0">${escapeHtml(strings.add)} ✓</span>`
                        : `<button type="button" class="btn-secondary btn-sm shrink-0" data-upsell-add="${product.id}">${needsOptions ? escapeHtml(strings.choose) : escapeHtml(strings.quick_add)}</button>`}
                </div>
            `;
        }).join('');
    }

    function renderCart() {
        const body = document.getElementById('cart-body');
        const footer = document.getElementById('cart-footer');
        const bar = document.getElementById('cart-bar');

        if (!body) {
            return;
        }

        if (cart.length === 0) {
            body.innerHTML = `
                <div class="flex h-full flex-col items-center justify-center px-6 py-16 text-center">
                    <span class="grid size-16 place-items-center rounded-3xl bg-cream text-muted"><svg viewBox="0 0 24 24" class="size-8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l1 14H5L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg></span>
                    <p class="mt-4 font-display text-lg font-semibold text-ink">${escapeHtml(strings.cart_empty)}</p>
                    <p class="mt-1 max-w-xs text-sm text-muted">${escapeHtml(strings.cart_empty_hint)}</p>
                    <button type="button" class="btn-primary mt-5" data-drawer-close>${escapeHtml(strings.browse_menu)}</button>
                </div>
            `;

            if (footer) {
                footer.hidden = true;
            }
        } else {
            body.innerHTML = cart.map((line) => {
                const product = productsById(line.productId);
                const key = encodeURIComponent(lineKey(line));
                const options = line.optionIds
                    .map((id) => optionById.get(Number(id))?.name)
                    .filter(Boolean)
                    .join(' · ');

                return `
                    <div class="flex gap-3 rounded-2xl border border-line bg-paper p-3">
                        <div class="size-16 shrink-0 overflow-hidden rounded-xl bg-cream">${productImage(product, 'size-16', 'size-6')}</div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start gap-2">
                                <p class="min-w-0 flex-1 text-sm font-bold text-ink">${escapeHtml(product?.name ?? '')}</p>
                                <button type="button" class="grid size-7 shrink-0 place-items-center rounded-full text-muted transition hover:bg-danger-soft hover:text-danger" data-cart-remove="${key}" aria-label="${escapeHtml(strings.remove)}"><svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"/></svg></button>
                            </div>
                            ${options ? `<p class="mt-0.5 text-[11px] font-semibold text-muted">${escapeHtml(options)}</p>` : ''}
                            ${line.note ? `<p class="mt-0.5 text-[11px] italic text-muted">“${escapeHtml(line.note)}”</p>` : ''}
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1 rounded-full border border-line bg-cream p-0.5">
                                    <button type="button" class="grid size-7 place-items-center rounded-full text-ink transition hover:bg-paper" data-cart-change="${key}" data-delta="-1" aria-label="${escapeHtml(strings.remove)}">−</button>
                                    <span class="min-w-6 text-center text-xs font-bold">${line.quantity}</span>
                                    <button type="button" class="grid size-7 place-items-center rounded-full text-ink transition hover:bg-paper" data-cart-change="${key}" data-delta="1" aria-label="${escapeHtml(strings.add)}">+</button>
                                </div>
                                <span class="text-sm font-bold text-forest">${money(lineTotal(line))}</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            if (footer) {
                footer.hidden = false;
            }
        }

        if (bar) {
            bar.hidden = cart.length === 0;
        }

        const count = cart.reduce((sum, line) => sum + line.quantity, 0);
        document.querySelectorAll('[data-cart-count]').forEach((node) => {
            node.textContent = String(count);
        });
        document.querySelectorAll('[data-cart-total]').forEach((node) => {
            node.textContent = money(cartTotal());
        });

        const subtotalNode = document.querySelector('[data-cart-subtotal]');
        const discountNode = document.querySelector('[data-cart-discount]');
        const discountRow = document.querySelector('[data-cart-discount-row]');
        const totalNode = document.querySelector('[data-cart-total-line]');
        const checkout = document.querySelector('[data-checkout]');
        const subtotal = cartSubtotal();
        const discount = effectiveDiscount();

        if (subtotalNode) {
            subtotalNode.textContent = money(subtotal);
        }

        if (discountNode) {
            discountNode.textContent = `−${money(discount)}`;
        }

        if (discountRow) {
            discountRow.hidden = discount <= 0;
        }

        if (totalNode) {
            totalNode.textContent = money(cartTotal());
        }

        if (checkout) {
            checkout.disabled = cart.length === 0;
        }

        const note = document.getElementById('cart-note');

        if (note && document.activeElement !== note) {
            note.value = orderNote;
        }

        updateCouponUI();
        updateLoyaltyUI();
    }

    function campaignProgress(campaign) {
        const current = campaign.type === 'points' ? loyalty.points : loyalty.completed_orders;

        return Math.min(100, Math.round((current / Math.max(1, campaign.required)) * 100));
    }

    function updateLoyaltyUI() {
        const box = document.getElementById('loyalty-box');
        const points = document.getElementById('loyalty-points');
        const options = document.getElementById('loyalty-options');
        const note = document.getElementById('loyalty-note');

        if (!box || !options) {
            return;
        }

        if (!loyalty || loyalty.campaigns.length === 0) {
            box.hidden = true;
            return;
        }

        box.hidden = false;

        if (points) {
            points.textContent = strings.loyalty_points.replace(':points', String(loyalty.points));
        }

        if (coupon) {
            loyaltyCampaignId = null;
        }

        if (note) {
            note.hidden = !coupon;
        }

        options.innerHTML = loyalty.campaigns.map((campaign) => {
            const current = campaign.type === 'points' ? loyalty.points : loyalty.completed_orders;
            const thresholdMet = current >= campaign.required;
            const minimumMet = cartSubtotal() >= campaign.minimum_order_cents;
            const eligible = thresholdMet && minimumMet && !coupon;
            const selected = Number(loyaltyCampaignId) === Number(campaign.id);
            const lockedLabel = campaign.type === 'points'
                ? strings.loyalty_locked.replace(':required', String(campaign.required))
                : strings.loyalty_orders_locked.replace(':required', String(campaign.required));
            const detail = !thresholdMet
                ? lockedLabel
                : (!minimumMet ? strings.loyalty_min_order.replace(':amount', money(campaign.minimum_order_cents)) : campaign.reward_label);
            const countLabel = campaign.type === 'points'
                ? strings.loyalty_points.replace(':points', String(loyalty.points))
                : strings.loyalty_orders.replace(':count', String(loyalty.completed_orders));

            return `
                <button type="button" data-loyalty-id="${campaign.id}" ${eligible ? '' : 'disabled'} class="flex w-full items-center gap-3 rounded-xl border px-3 py-2 text-left transition ${selected ? 'border-forest bg-paper shadow-soft' : 'border-line bg-paper/60'} ${eligible ? 'hover:border-forest/40 hover:bg-paper' : 'cursor-not-allowed opacity-60'}">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full ${selected ? 'bg-forest text-white' : 'bg-cream text-forest'}">
                        ${selected
                            ? '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4.5 4.5L19 7"/></svg>'
                            : '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3 4 8l8 13 8-13-8-5Z"/><path d="M4 8h16"/></svg>'}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-bold text-ink">${escapeHtml(campaign.name)}</span>
                        <span class="mt-0.5 block truncate text-[10px] font-semibold text-muted">${escapeHtml(detail)} · ${escapeHtml(countLabel)}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-[10px] font-bold uppercase tracking-[.1em] ${selected ? 'text-forest' : 'text-muted'}">${selected ? escapeHtml(strings.loyalty_in_use) : escapeHtml(strings.loyalty_use)}</span>
                        <span class="mt-1 block h-1 w-16 overflow-hidden rounded-full bg-cream">
                            <span class="block h-full rounded-full ${selected ? 'bg-forest' : 'bg-amber'}" style="width:${campaignProgress(campaign)}%"></span>
                        </span>
                    </span>
                </button>
            `;
        }).join('');
    }

    function updateCouponUI() {
        const status = document.getElementById('coupon-status');
        const text = document.getElementById('coupon-status-text');
        const input = document.getElementById('coupon-code');
        const message = document.getElementById('coupon-message');
        const invalid = coupon && effectiveDiscount() === 0;

        if (status) {
            status.hidden = !coupon;
        }

        if (text && coupon) {
            text.textContent = invalid
                ? `${coupon.code} · ${strings.coupon_invalid}`
                : `${coupon.code} · −${money(effectiveDiscount())}`;
            text.className = invalid
                ? 'flex-1 text-xs font-bold text-danger'
                : 'flex-1 text-xs font-bold text-success';
        }

        if (input) {
            if (coupon) {
                input.value = coupon.code;
            }
            input.readOnly = Boolean(coupon);
        }

        if (message && !coupon) {
            message.hidden = true;
            message.textContent = '';
        }
    }

    function scheduleCouponRevalidation() {
        if (!coupon) {
            return;
        }

        window.clearTimeout(couponTimer);
        couponTimer = window.setTimeout(revalidateCoupon, 350);
    }

    async function revalidateCoupon() {
        if (!coupon) {
            return;
        }

        if (cart.length === 0) {
            coupon = null;
            renderCart();
            return;
        }

        try {
            const response = await apiFetch(data.urls.coupon, {
                method: 'POST',
                body: { code: coupon.code, subtotal_cents: cartSubtotal() },
            });

            if (!response.ok) {
                coupon = null;
                renderCart();
                toast(response.message || strings.coupon_invalid, 'error');
                return;
            }

            coupon = {
                code: response.code,
                discount_cents: response.discount_cents,
                min_subtotal_cents: response.min_subtotal_cents,
            };
            renderCart();
        } catch {
            // Keep the last known coupon; the server validates again on submit.
        }
    }

    function renderConfirm() {
        const body = document.getElementById('confirm-body');

        if (!body) {
            return;
        }

        const subtotal = cartSubtotal();
        const discount = effectiveDiscount();
        const total = Math.max(0, subtotal - discount);
        const note = orderNote.trim();
        const activeReward = !coupon ? activeLoyaltyCampaign() : null;
        const discountLabel = coupon ? coupon.code : (activeReward?.name ?? '');

        body.innerHTML = `
            <div class="space-y-2">
                ${cart.map((line) => {
                    const product = productsById(line.productId);
                    const options = line.optionIds
                        .map((id) => optionById.get(Number(id))?.name)
                        .filter(Boolean)
                        .join(' · ');

                    return `
                        <div class="flex items-start gap-3 rounded-2xl border border-line bg-paper p-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-forest text-[11px] font-bold text-white">${line.quantity}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-bold text-ink">${escapeHtml(product?.name ?? '')}</p>
                                    <p class="shrink-0 text-sm font-bold text-ink">${money(lineTotal(line))}</p>
                                </div>
                                ${options ? `<p class="mt-0.5 text-[11px] text-muted">${escapeHtml(options)}</p>` : ''}
                                ${line.note ? `<p class="mt-0.5 text-[11px] italic text-muted">“${escapeHtml(line.note)}”</p>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
            <div class="mt-4 rounded-2xl bg-cream p-4">
                <div class="flex items-center justify-between text-sm"><span class="text-muted">${escapeHtml(strings.subtotal)}</span><span class="font-semibold text-ink">${money(subtotal)}</span></div>
                ${discount > 0 ? `<div class="mt-2 flex items-center justify-between text-sm"><span class="text-muted">${escapeHtml(strings.discount)} ${discountLabel ? `(${escapeHtml(discountLabel)})` : ''}</span><span class="font-semibold text-success">−${money(discount)}</span></div>` : ''}
                <div class="mt-3 flex items-center justify-between border-t border-line pt-3"><span class="text-sm font-bold text-ink">${escapeHtml(strings.total)}</span><span class="font-display text-xl font-semibold text-forest" data-confirm-total>${money(total)}</span></div>
            </div>
            <div class="mt-4 grid gap-2 text-xs text-muted">
                <p class="flex items-center gap-2"><svg viewBox="0 0 24 24" class="size-4 text-forest" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/></svg>${escapeHtml(strings.table)} ${escapeHtml(data.table.name)} · ${escapeHtml(data.branch.name)}</p>
                ${note ? `<p class="rounded-xl bg-paper px-3 py-2 text-ink"><span class="font-bold">${escapeHtml(strings.order_note)}:</span> ${escapeHtml(note)}</p>` : ''}
                <p class="flex items-center gap-2"><svg viewBox="0 0 24 24" class="size-4 text-forest" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>${escapeHtml(strings.confirm_hint)}</p>
            </div>
            <p id="confirm-error" class="mt-3 hidden rounded-xl bg-danger-soft px-3 py-2 text-xs font-bold text-danger"></p>
        `;

        const totalNode = document.querySelector('[data-confirm-total-footer]');

        if (totalNode) {
            totalNode.textContent = money(total);
        }
    }

    function openConfirm() {
        if (cart.length === 0) {
            return;
        }

        closeDrawer('cart');
        renderConfirm();
        window.setTimeout(() => openSheet('confirm'), 200);
    }

    async function submitOrder(button) {
        if (cart.length === 0) {
            return;
        }

        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<span class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span> ${escapeHtml(strings.sending)}`;
        const errorBox = document.getElementById('confirm-error');

        try {
            const response = await apiFetch(data.urls.order, {
                method: 'POST',
                body: {
                    lines: cart.map((line) => ({
                        product_id: line.productId,
                        quantity: line.quantity,
                        option_ids: line.optionIds,
                        note: line.note || null,
                    })),
                    customer_note: orderNote.trim() || null,
                    coupon_code: coupon?.code ?? null,
                    loyalty_campaign_id: coupon ? null : loyaltyCampaignId,
                },
            });

            try {
                localStorage.removeItem(cartKey);
                localStorage.removeItem(noteKey);
            } catch {
                // Ignore storage failures.
            }

            toast(response.message || strings.order_sent, 'success');
            window.location.href = response.redirect || data.urls.menu;
        } catch (error) {
            const message = error.payload?.errors
                ? Object.values(error.payload.errors).flat()[0]
                : (error.message || strings.order_error);

            if (errorBox) {
                errorBox.textContent = message;
                errorBox.classList.remove('hidden');
            }

            toast(message, 'error');
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    function openCartDrawer() {
        if (cart.length === 0) {
            toast(strings.cart_empty, 'info');
        }

        renderCart();
        openDrawer('cart');
    }

    function renderSession(session) {
        updateBillRequestAvailability(session);

        const banner = document.getElementById('session-banner');

        if (!banner) {
            return;
        }

        if (!session || session.orders_count === 0) {
            banner.hidden = true;
            banner.innerHTML = '';
            return;
        }

        const latest = session.orders[session.orders.length - 1];
        const label = strings[`status_${latest.status}`] ?? latest.status_label;

        banner.hidden = false;
        banner.innerHTML = `
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 text-xs sm:px-6">
                <span class="inline-flex items-center gap-1.5 font-bold text-ink"><span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-50"></span><span class="relative inline-flex size-2 rounded-full bg-success"></span></span>${escapeHtml(strings.open_bill)}</span>
                <span class="font-semibold text-muted">${escapeHtml(session.orders_count)} ${escapeHtml(strings.items_count.replace(':count', '').trim())}</span>
                <span class="font-semibold text-muted">${escapeHtml(strings.total)}: <span class="text-ink">${escapeHtml(session.total)}</span></span>
                ${session.due_cents > 0 ? `<span class="font-semibold text-muted">${escapeHtml(strings.remaining)}: <span class="text-forest">${escapeHtml(session.due)}</span></span>` : ''}
                <span class="hidden rounded-full bg-cream px-2.5 py-1 font-bold text-ink sm:inline-flex">${escapeHtml(latest.number)} · ${escapeHtml(label)}</span>
                <a href="${escapeHtml(data.urls.bill)}" class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-forest px-3 py-1.5 font-bold text-white transition hover:bg-ink">${escapeHtml(strings.view_bill)}<svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            </div>
        `;

        session.orders.forEach((order) => {
            const previous = lastStatuses.get(order.number);

            if (statusesInitialised && previous && previous !== order.status && order.status !== 'cancelled') {
                const statusLabel = strings[`status_${order.status}`] ?? order.status_label;
                toast(`${order.number} · ${statusLabel}`, order.status === 'ready' ? 'success' : 'info');
            }

            lastStatuses.set(order.number, order.status);
        });

        statusesInitialised = true;
    }

    /* Events */

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target) && !event.target.closest('[data-sheet="cart"], [data-sheet="confirm"], [data-sheet="product"], [data-sheet="upsell"]')) {
            return;
        }

        const quickAdd = event.target.closest('[data-quick-add]');
        if (quickAdd) {
            event.preventDefault();
            const product = productsById(quickAdd.dataset.quickAdd);

            if (!product || !product.is_available) {
                toast(strings.sold_out, 'error');
                return;
            }

            const needsOptions = product.option_groups.some((group) => group.min_selections > 0);

            if (needsOptions) {
                openProduct(product.id);
                return;
            }

            addLine(product.id, 1, [], '');
            toast(`${product.name} · ${strings.cart_added}`, 'success');

            if (product.suggestions.length > 0) {
                window.setTimeout(() => openUpsell(product), 220);
            }
            return;
        }

        const productOpen = event.target.closest('[data-product-open]');
        if (productOpen) {
            event.preventDefault();
            openProduct(productOpen.dataset.productOpen);
            return;
        }

        const category = event.target.closest('[data-category]');
        if (category) {
            event.preventDefault();
            activeCategory = category.dataset.category;
            renderChips();
            renderContent();

            if (activeCategory !== 'all') {
                document.getElementById(`category-${activeCategory}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }

        const productQty = event.target.closest('[data-product-qty]');
        if (productQty && sheetState) {
            event.preventDefault();
            sheetState.qty = Math.max(1, Math.min(99, sheetState.qty + Number(productQty.dataset.productQty)));
            refreshSheetPrice();
            return;
        }

        if (event.target.closest('[data-add-to-cart]')) {
            event.preventDefault();
            addFromSheet();
            return;
        }

        const upsellAdd = event.target.closest('[data-upsell-add]');
        if (upsellAdd && upsellState) {
            event.preventDefault();
            const product = productsById(upsellAdd.dataset.upsellAdd);

            if (!product || !product.is_available) {
                return;
            }

            const needsOptions = product.option_groups.some((group) => group.min_selections > 0);

            if (needsOptions) {
                closeSheet('upsell');
                window.setTimeout(() => openProduct(product.id), 200);
                return;
            }

            addLine(product.id, 1, [], '');
            upsellState.added.add(Number(product.id));
            renderUpsell();
            toast(`${product.name} · ${strings.cart_added}`, 'success');
            return;
        }

        if (event.target.closest('[data-upsell-cart]')) {
            event.preventDefault();
            closeSheet('upsell');
            window.setTimeout(openCartDrawer, 200);
            return;
        }

        if (event.target.closest('[data-cart-open]')) {
            event.preventDefault();
            openCartDrawer();
            return;
        }

        const cartChange = event.target.closest('[data-cart-change]');
        if (cartChange) {
            event.preventDefault();
            changeLineQuantity(decodeURIComponent(cartChange.dataset.cartChange), Number(cartChange.dataset.delta));
            return;
        }

        const cartRemove = event.target.closest('[data-cart-remove]');
        if (cartRemove) {
            event.preventDefault();
            removeLine(decodeURIComponent(cartRemove.dataset.cartRemove));
            return;
        }

        if (event.target.closest('[data-coupon-remove]')) {
            event.preventDefault();
            coupon = null;

            const message = document.getElementById('coupon-message');
            if (message) {
                message.hidden = true;
            }

            renderCart();
            toast(strings.coupon_removed, 'info');
            return;
        }

        const loyaltyButton = event.target.closest('[data-loyalty-id]');
        if (loyaltyButton) {
            event.preventDefault();

            if (loyaltyButton.disabled) {
                return;
            }

            const id = Number(loyaltyButton.dataset.loyaltyId);
            loyaltyCampaignId = Number(loyaltyCampaignId) === id ? null : id;

            if (loyaltyCampaignId !== null) {
                coupon = null;
                const couponInput = document.getElementById('coupon-code');
                const couponMessage = document.getElementById('coupon-message');

                if (couponInput) {
                    couponInput.value = '';
                    couponInput.readOnly = false;
                }

                if (couponMessage) {
                    couponMessage.hidden = true;
                    couponMessage.textContent = '';
                }
            }

            renderCart();
            return;
        }

        if (event.target.closest('[data-checkout]')) {
            event.preventDefault();
            openConfirm();
            return;
        }

        const confirmButton = event.target.closest('[data-confirm-order]');
        if (confirmButton) {
            event.preventDefault();
            submitOrder(confirmButton);
        }
    });

    document.addEventListener('change', (event) => {
        if (!sheetState) {
            return;
        }

        const choice = event.target.closest('[data-option-choice]');

        if (!choice || !document.querySelector('[data-sheet="product"]')?.contains(choice)) {
            return;
        }

        const groupElement = choice.closest('[data-option-group-id]');

        if (!groupElement) {
            return;
        }

        const group = sheetState.product.option_groups.find((item) => String(item.id) === String(groupElement.dataset.optionGroupId));

        if (!group) {
            return;
        }

        const id = Number(choice.value);

        if (group.selection_mode === 'single') {
            group.options.forEach((option) => sheetState.optionIds.delete(Number(option.id)));
            sheetState.optionIds.add(id);
        } else if (choice.checked) {
            const max = group.max_selections ?? group.options.length;
            const selected = group.options.filter((option) => sheetState.optionIds.has(Number(option.id))).length;

            if (selected >= max) {
                choice.checked = false;
                toast(`${group.name}: en fazla ${max} seçim.`, 'error');
                return;
            }

            sheetState.optionIds.add(id);
        } else {
            sheetState.optionIds.delete(id);
        }

        document.getElementById('product-sheet-error')?.classList.add('hidden');
        refreshSheetPrice();
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches('[data-item-note]') && sheetState) {
            sheetState.note = event.target.value;
        }

        if (event.target.id === 'cart-note') {
            orderNote = event.target.value.slice(0, 500);
            persistNote();
        }

        if (event.target.id === 'menu-search') {
            search = event.target.value;
            renderContent();
        }
    });

    const searchInput = document.getElementById('menu-search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            search = searchInput.value;
            renderContent();
        });
    }

    const couponForm = document.getElementById('coupon-form');
    if (couponForm) {
        couponForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const input = document.getElementById('coupon-code');
            const message = document.getElementById('coupon-message');
            const code = input?.value.trim() ?? '';

            if (!code) {
                return;
            }

            if (cart.length === 0) {
                if (message) {
                    message.textContent = strings.cart_empty;
                    message.className = 'mt-2 text-xs font-bold text-danger';
                    message.hidden = false;
                }
                return;
            }

            try {
                const response = await apiFetch(data.urls.coupon, {
                    method: 'POST',
                    body: { code, subtotal_cents: cartSubtotal() },
                });

                if (!response.ok) {
                    coupon = null;
                    if (message) {
                        message.textContent = response.message || strings.coupon_invalid;
                        message.className = 'mt-2 text-xs font-bold text-danger';
                        message.hidden = false;
                    }
                    renderCart();
                    return;
                }

                coupon = {
                    code: response.code,
                    discount_cents: response.discount_cents,
                    min_subtotal_cents: response.min_subtotal_cents,
                };

                if (message) {
                    message.hidden = true;
                }

                renderCart();
                toast(strings.coupon_applied, 'success');
            } catch (error) {
                const errorMessage = error.payload?.errors
                    ? Object.values(error.payload.errors).flat()[0]
                    : (error.message || strings.coupon_invalid);

                if (message) {
                    message.textContent = errorMessage;
                    message.className = 'mt-2 text-xs font-bold text-danger';
                    message.hidden = false;
                }
            }
        });
    }

    // Initial paint.
    renderChips();
    renderContent();
    renderCart();
    renderSession(data.session);

    const note = document.getElementById('cart-note');
    if (note) {
        note.value = orderNote;
    }

    startPolling(async () => {
        const response = await apiFetch(data.urls.status);
        renderSession(response.session);
    }, 15000, { immediate: false });
}

/* -------------------------------------------------------------------------
 * Service requests (menu, track and bill pages)
 * ---------------------------------------------------------------------- */

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-service-request]');

    if (!button) {
        return;
    }

    event.preventDefault();
    const url = button.dataset.serviceUrl;
    const type = button.dataset.serviceRequest;

    if (!url) {
        return;
    }

    button.disabled = true;
    const original = button.innerHTML;
    const billLabel = button.querySelector('[data-bill-request-label]');
    const originalBillLabel = billLabel?.textContent ?? '';

    if (type === 'bill') {
        button.dataset.billRequestCoolingDown = 'true';
    }

    try {
        const response = await apiFetch(url, { method: 'POST', body: { type } });
        toast(response.message, 'success');
        if (type === 'bill' && billLabel) {
            billLabel.textContent = `✓ ${button.dataset.requestSentLabel || 'İletildi'}`;
        } else {
            button.innerHTML = '✓ İletildi';
        }
        window.setTimeout(() => {
            if (type === 'bill') {
                delete button.dataset.billRequestCoolingDown;
                if (billLabel) {
                    billLabel.textContent = originalBillLabel;
                }
                updateBillRequestAvailability({
                    can_request_bill: button.dataset.billRequestAvailable === 'true',
                });
            } else {
                button.disabled = false;
                button.innerHTML = original;
            }
        }, 30000);
    } catch (error) {
        toast(error.message || 'İstek iletilemedi.', 'error');

        if (type === 'bill') {
            delete button.dataset.billRequestCoolingDown;
            if (error.payload?.code === 'order_not_accepted') {
                button.dataset.billRequestAvailable = 'false';
            }
            if (billLabel) {
                billLabel.textContent = originalBillLabel;
            }
            updateBillRequestAvailability({
                can_request_bill: button.dataset.billRequestAvailable === 'true',
            });
        } else {
            button.disabled = false;
            button.innerHTML = original;
        }
    }
});

/* -------------------------------------------------------------------------
 * Order tracking
 * ---------------------------------------------------------------------- */

const trackRoot = document.getElementById('order-track');

if (trackRoot) {
    initTracking(trackRoot);
}

function initTracking(root) {
    const config = readJson('track-data');

    if (!config) {
        return;
    }

    let currentStatus = config.order.status;

    updateBillRequestAvailability({ can_request_bill: config.canRequestBill });

    const applyOrder = (order) => {
        const strings = config.strings;
        const label = strings[`status_${order.status}`] ?? order.status_label;

        root.querySelectorAll('[data-track-status]').forEach((node) => {
            node.textContent = label;
        });

        const badge = root.querySelector('[data-track-badge]');

        if (badge) {
            badge.className = `badge ${statusBadgeClass(order.status)}`.replace('badge badge-', 'badge-');
            badge.textContent = label;
        }

        const help = root.querySelector('[data-track-help]');

        if (help) {
            help.textContent = strings[`status_help_${order.status}`] ?? '';
        }

        const steps = ['pending', 'accepted', 'ready', 'served'];
        const index = steps.indexOf(order.status);

        root.querySelectorAll('[data-step]').forEach((step) => {
            const stepIndex = steps.indexOf(step.dataset.step);
            const dot = step.querySelector('[data-step-dot]');
            const text = step.querySelector('[data-step-label]');

            if (!dot) {
                return;
            }

            dot.className = 'timeline-dot';
            step.dataset.state = '';

            if (order.status === 'cancelled') {
                return;
            }

            if (index >= 0 && stepIndex < index) {
                dot.className = 'timeline-dot-done';
                dot.innerHTML = '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>';
                step.dataset.state = 'done';
            } else if (index >= 0 && stepIndex === index) {
                dot.className = 'timeline-dot-active';
                dot.innerHTML = `<span class="size-2 rounded-full bg-current"></span>`;
                step.dataset.state = 'active';
            } else {
                dot.innerHTML = `<span class="size-1.5 rounded-full bg-current opacity-40"></span>`;
            }

            if (text) {
                text.className = step.dataset.state === 'active' ? 'text-xs font-bold text-ink' : 'text-xs font-semibold text-muted';
            }
        });

        const cancel = root.querySelector('[data-track-cancelled]');

        if (cancel) {
            cancel.hidden = order.status !== 'cancelled';
        }

        if (order.status === 'paid') {
            const paid = root.querySelector('[data-track-paid]');
            if (paid) {
                paid.hidden = false;
            }
        }

        if (config.order.status !== order.status) {
            config.order.status = order.status;
            if (order.status === 'ready') {
                window.skykite?.chime?.();
                toast(`${order.number} · ${label}`, 'success');
            } else if (order.status !== 'cancelled') {
                toast(`${order.number} · ${label}`, 'info');
            }
        }

        currentStatus = order.status;
    };

    startPolling(async () => {
        const response = await apiFetch(`${config.statusUrl}?order=${encodeURIComponent(config.orderNumber)}`);

        if (response.order) {
            applyOrder(response.order);
        }

        if (response.session) {
            updateBillRequestAvailability(response.session);
            root.querySelectorAll('[data-track-due]').forEach((node) => {
                node.textContent = response.session.due;
            });
            root.querySelectorAll('[data-track-total]').forEach((node) => {
                node.textContent = response.session.total;
            });
        }
    }, 5000, { immediate: false });
}

/* -------------------------------------------------------------------------
 * Bill page — reload when the live snapshot changes
 * ---------------------------------------------------------------------- */

const billRoot = document.getElementById('bill-page');

if (billRoot) {
    const config = readJson('bill-data');

    if (config) {
        updateBillRequestAvailability({ can_request_bill: config.canRequestBill });

        startPolling(async () => {
            const response = await apiFetch(config.statusUrl);
            const session = response.session;

            updateBillRequestAvailability(session);

            const signature = session
                ? [session.status, session.orders_count, session.due_cents, session.orders.map((order) => order.status).join(',')].join('|')
                : 'closed';

            if (signature !== config.signature) {
                window.location.reload();
            }
        }, 5000, { immediate: false });
    }
}
