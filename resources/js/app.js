import { toast } from './core.js';
import './customer-menu.js';
import './staff-live.js';

document.querySelectorAll('[data-loyalty-campaign-form]').forEach((form) => {
    const type = form.querySelector('[name="reward_type"]');
    const valueField = form.querySelector('[data-reward-value-field]');
    const valueInput = valueField?.querySelector('[name="reward_value"]');
    const productsField = form.querySelector('[data-reward-products-field]');
    const productInputs = [...(productsField?.querySelectorAll('[name="reward_product_ids[]"]') ?? [])];
    const productSearch = productsField?.querySelector('[data-reward-product-search]');
    const productOptions = [...(productsField?.querySelectorAll('[data-reward-product-option]') ?? [])];

    productSearch?.addEventListener('input', () => {
        const query = productSearch.value.trim().toLocaleLowerCase('tr-TR');
        productOptions.forEach((option) => {
            const name = option.querySelector('[data-reward-product-name]')?.textContent?.trim().toLocaleLowerCase('tr-TR') ?? '';
            option.hidden = !name.includes(query);
        });
    });

    const update = () => {
        const productReward = type?.value === 'product';
        if (valueField) valueField.hidden = productReward;
        if (valueInput) {
            valueInput.required = !productReward;
            valueInput.disabled = productReward;
        }
        if (productsField) productsField.hidden = !productReward;
        productInputs.forEach((input) => { input.disabled = !productReward; });
    };

    type?.addEventListener('change', update);
    form.addEventListener('submit', (event) => {
        if (type?.value === 'product' && !productInputs.some((input) => input.checked)) {
            event.preventDefault();
            const first = productInputs[0];
            first?.setCustomValidity('Ücretsiz ödül için en az bir ürün seçin.');
            first?.reportValidity();
            first?.addEventListener('change', () => first.setCustomValidity(''), { once: true });
        }
    });
    update();
});

const suggestionTemplate = document.querySelector('#menu-suggestion-options');
const suggestionCategoryTemplate = document.querySelector('#menu-suggestion-category-options');

document.querySelectorAll('[data-featured-picker]').forEach((picker) => {
    const options = picker.querySelector('[data-featured-options]');
    const count = picker.closest('section')?.querySelector('[data-featured-count]');
    const message = picker.querySelector('[data-featured-message]');
    const update = () => {
        const selected = options?.querySelectorAll('input[type="checkbox"]:checked').length ?? 0;
        if (count) count.textContent = `${selected} / 8 seçili`;
    };
    const filter = () => {
        const categoryId = picker.dataset.activeFeaturedCategory ?? 'all';
        const query = picker.querySelector('[data-featured-search]')?.value.trim().toLocaleLowerCase('tr-TR') ?? '';
        picker.querySelectorAll('[data-featured-category-button]').forEach((button) => {
            const active = button.dataset.categoryId === categoryId;
            button.setAttribute('aria-pressed', String(active));
            button.classList.toggle('border-forest', active);
            button.classList.toggle('bg-sage', active);
            button.classList.toggle('text-forest', active);
            button.classList.toggle('border-line', !active);
            button.classList.toggle('bg-paper', !active);
            button.classList.toggle('text-ink', !active);
        });
        options?.querySelectorAll('[data-featured-option]').forEach((option) => {
            const name = option.querySelector('[data-featured-name]')?.textContent?.trim().toLocaleLowerCase('tr-TR') ?? '';
            const hidden = (categoryId !== 'all' && option.dataset.categoryId !== categoryId) || !name.includes(query);
            option.hidden = hidden;
            option.style.display = hidden ? 'none' : '';
        });
    };

    options?.addEventListener('change', (event) => {
        const checkbox = event.target.closest('input[type="checkbox"]');
        if (checkbox?.checked && options.querySelectorAll('input[type="checkbox"]:checked').length > 8) {
            checkbox.checked = false;
            if (message) message.textContent = 'En fazla 8 ürün seçebilirsiniz.';
        } else if (message) {
            message.textContent = '';
        }
        update();
    });
    picker.querySelectorAll('[data-featured-category-button]').forEach((button) => {
        button.addEventListener('click', () => {
            picker.dataset.activeFeaturedCategory = button.dataset.categoryId ?? 'all';
            filter();
        });
    });
    picker.querySelector('[data-featured-search]')?.addEventListener('input', filter);
    picker.querySelector('[data-featured-select-visible]')?.addEventListener('click', () => {
        const checkboxes = [...(options?.querySelectorAll('[data-featured-option]:not([hidden]) input[type="checkbox"]') ?? [])];
        let reachedLimit = false;
        for (const checkbox of checkboxes) {
            if (options.querySelectorAll('input[type="checkbox"]:checked').length >= 8) {
                reachedLimit = true;
                break;
            }
            checkbox.checked = true;
        }
        if (reachedLimit && message) message.textContent = 'En fazla 8 ürün seçebilirsiniz.';
        update();
    });
    picker.querySelector('[data-featured-clear]')?.addEventListener('click', () => {
        options?.querySelectorAll('input[type="checkbox"]:checked').forEach((checkbox) => { checkbox.checked = false; });
        if (message) message.textContent = '';
        update();
    });
    filter();
    update();
});

const setupSuggestionPicker = (picker, options) => {
    if (!picker || !options || picker.dataset.controlsReady === 'true') return;

    const count = picker.querySelector('[data-suggestion-count]');
    const maximum = picker.hasAttribute('data-suggestion-category-picker') ? 20 : 6;
    const limitMessage = document.createElement('p');
    limitMessage.className = 'text-xs font-semibold text-danger';
    limitMessage.setAttribute('role', 'status');
    limitMessage.setAttribute('aria-live', 'polite');
    picker.append(limitMessage);
    const updateCount = () => {
        const selected = options.querySelectorAll('input[type="checkbox"]:checked').length;
        if (count) count.textContent = `${selected} / ${maximum} seçili`;
    };

    const filterProducts = () => {
        const categoryId = picker.dataset.activeProductCategory ?? '';
        const query = picker.querySelector('[data-suggestion-search]')?.value.trim().toLocaleLowerCase('tr-TR') ?? '';
        const search = picker.querySelector('[data-suggestion-search]');
        if (search) search.disabled = categoryId === '';
        picker.querySelectorAll('[data-suggestion-category-filter]').forEach((button) => {
            const active = button.dataset.categoryId === categoryId;
            button.setAttribute('aria-pressed', String(active));
            button.classList.toggle('border-forest', active);
            button.classList.toggle('bg-sage', active);
            button.classList.toggle('text-forest', active);
            button.classList.toggle('bg-paper', !active);
            button.classList.toggle('text-ink', !active);
        });
        let visibleCount = 0;
        options.querySelectorAll('[data-suggestion-option]').forEach((option) => {
            const name = option.querySelector('[data-suggestion-product-name]')?.textContent?.trim().toLocaleLowerCase('tr-TR') ?? '';
            const categoryMatches = categoryId !== '' && option.dataset.suggestionCategoryId === categoryId;
            const hidden = !categoryMatches || !name.includes(query);
            option.hidden = hidden;
            option.style.display = hidden ? 'none' : '';
            if (!option.hidden) visibleCount += 1;
        });
        let emptyState = options.querySelector('[data-suggestion-empty]');
        if (!emptyState) {
            emptyState = document.createElement('p');
            emptyState.dataset.suggestionEmpty = '';
            emptyState.className = 'col-span-full py-3 text-center text-sm font-medium text-muted';
            options.append(emptyState);
        }
        emptyState.hidden = visibleCount > 0;
        emptyState.textContent = categoryId === ''
            ? 'Ürünleri görmek için yukarıdan bir kategori seçin.'
            : 'Bu kategoride aramanızla eşleşen ürün bulunamadı.';
    };

    options.addEventListener('change', (event) => {
        const checkbox = event.target.closest?.('input[type="checkbox"]');
        if (checkbox?.checked && options.querySelectorAll('input[type="checkbox"]:checked').length > maximum) {
            checkbox.checked = false;
            limitMessage.textContent = picker.hasAttribute('data-suggestion-category-picker')
                ? 'En fazla 20 tetikleyici kategori seçebilirsiniz.'
                : 'En fazla 6 ürün önerisi seçebilirsiniz.';
        } else if (checkbox) {
            limitMessage.textContent = '';
        }
        updateCount();
    });
    picker.querySelector('[data-select-visible]')?.addEventListener('click', () => {
        let reachedLimit = false;
        options.querySelectorAll('[data-suggestion-option], [data-suggestion-category-option]').forEach((option) => {
            if (option.hidden) return;
            const checkbox = option.querySelector('input[type="checkbox"]');
            if (!checkbox || checkbox.checked) return;
            if (options.querySelectorAll('input[type="checkbox"]:checked').length >= maximum) {
                reachedLimit = true;
                return;
            }
            checkbox.checked = true;
        });
        limitMessage.textContent = reachedLimit
            ? picker.hasAttribute('data-suggestion-category-picker')
                ? 'En fazla 20 tetikleyici kategori seçebilirsiniz.'
                : 'En fazla 6 ürün önerisi seçebilirsiniz.'
            : '';
        updateCount();
    });
    picker.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
        options.querySelectorAll('input[type="checkbox"]:checked').forEach((checkbox) => { checkbox.checked = false; });
        limitMessage.textContent = '';
        updateCount();
    });

    updateCount();
    picker.dataset.controlsReady = 'true';
};

const initializeRecommendationDetails = (details) => {
    if (!details.open || details.dataset.pickerInitialized === 'true') return;

    const picker = details.querySelector('[data-suggestion-picker]');
    const options = picker?.querySelector('[data-suggestion-options]');
    if (picker && options && options.dataset.loaded !== 'true' && suggestionTemplate) {
        const currentProductId = String(details.dataset.productId ?? '');
        const selectedIds = new Set((picker.dataset.selectedSuggestions ?? '').split(',').filter(Boolean));
        options.replaceChildren();
        suggestionTemplate.content.querySelectorAll('[data-suggestion-option]').forEach((option) => {
            if (option.dataset.suggestionProductId === currentProductId) return;

            const clone = option.cloneNode(true);
            const checkbox = clone.querySelector('input[type="checkbox"]');
            checkbox.name = 'suggestions[]';
            checkbox.checked = selectedIds.has(checkbox.value);
            options.append(clone);
        });
        options.dataset.loaded = 'true';

        const firstSelectedProduct = options.querySelector('[data-suggestion-option] input[type="checkbox"]:checked')?.closest('[data-suggestion-option]');
        if (firstSelectedProduct) picker.dataset.activeProductCategory = firstSelectedProduct.dataset.suggestionCategoryId ?? '';

        const filterProducts = () => {
            const categoryId = picker.dataset.activeProductCategory ?? '';
            const query = picker.querySelector('[data-suggestion-search]')?.value.trim().toLocaleLowerCase('tr-TR') ?? '';
            const search = picker.querySelector('[data-suggestion-search]');
            if (search) {
                search.disabled = categoryId === '';
                search.placeholder = categoryId === '' ? 'Önce yukarıdan kategori seçin' : 'Seçili kategoride ürün ara...';
            }
            let visibleCount = 0;
            options.querySelectorAll('[data-suggestion-option]').forEach((option) => {
                const name = option.querySelector('[data-suggestion-product-name]')?.textContent?.trim().toLocaleLowerCase('tr-TR') ?? '';
                const categoryMatches = categoryId !== '' && option.dataset.suggestionCategoryId === categoryId;
                option.hidden = !categoryMatches || !name.includes(query);
                if (!option.hidden) visibleCount += 1;
            });
            let emptyState = options.querySelector('[data-suggestion-empty]');
            if (!emptyState) {
                emptyState = document.createElement('p');
                emptyState.dataset.suggestionEmpty = '';
                emptyState.className = 'col-span-full py-3 text-center text-sm font-medium text-muted';
                options.append(emptyState);
            }
            emptyState.hidden = visibleCount > 0;
            emptyState.textContent = categoryId === ''
                ? 'Ürünleri görmek için yukarıdan bir kategori seçin.'
                : 'Bu kategoride aramanızla eşleşen ürün bulunamadı.';
            picker.querySelectorAll('[data-suggestion-category-filter]').forEach((button) => {
                const active = button.dataset.categoryId === categoryId;
                button.setAttribute('aria-pressed', String(active));
                button.classList.toggle('border-forest', active);
                button.classList.toggle('bg-sage', active);
                button.classList.toggle('text-forest', active);
                button.classList.toggle('bg-paper', !active);
                button.classList.toggle('text-ink', !active);
            });
        };

        picker.querySelectorAll('[data-suggestion-category-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                picker.dataset.activeProductCategory = button.dataset.categoryId ?? '';
                filterProducts();
            });
        });
        picker.querySelector('[data-suggestion-search]')?.addEventListener('input', filterProducts);
        filterProducts();
    }
    setupSuggestionPicker(picker, options);

    const categoryPicker = details.querySelector('[data-suggestion-category-picker]');
    const categoryOptions = categoryPicker?.querySelector('[data-suggestion-category-options]');
    if (categoryPicker && categoryOptions && categoryOptions.dataset.loaded !== 'true' && suggestionCategoryTemplate) {
        const selectedCategoryIds = new Set((categoryPicker.dataset.selectedCategories ?? '').split(',').filter(Boolean));
        categoryOptions.replaceChildren();
        suggestionCategoryTemplate.content.querySelectorAll('[data-suggestion-category-option]').forEach((option) => {
            const clone = option.cloneNode(true);
            const checkbox = clone.querySelector('input[type="checkbox"]');
            checkbox.name = 'suggestion_category_ids[]';
            checkbox.checked = selectedCategoryIds.has(checkbox.value);
            categoryOptions.append(clone);
        });
        categoryOptions.dataset.loaded = 'true';

        categoryPicker.querySelector('[data-suggestion-category-search]')?.addEventListener('input', (searchEvent) => {
            const query = searchEvent.currentTarget.value.trim().toLocaleLowerCase('tr-TR');
            categoryOptions.querySelectorAll('[data-suggestion-category-option]').forEach((option) => {
                const name = option.querySelector('[data-suggestion-category-name]')?.textContent?.trim().toLocaleLowerCase('tr-TR') ?? '';
                const hidden = !name.includes(query);
                option.hidden = hidden;
                option.style.display = hidden ? 'none' : '';
            });
        });
    }
    setupSuggestionPicker(categoryPicker, categoryOptions);
    details.dataset.pickerInitialized = 'true';
};

document.querySelectorAll('[data-recommendation-details]').forEach((details) => {
    const initialize = () => initializeRecommendationDetails(details);
    details.addEventListener('toggle', initialize);
    details.querySelector('summary')?.addEventListener('click', () => window.setTimeout(initialize, 0));
    initialize();
});

const categoryStrip = document.querySelector('[data-category-strip]');
if (categoryStrip) {
    const categoryScrollButtons = [...document.querySelectorAll('[data-category-scroll]')];
    const updateCategoryScrollButtons = () => {
        const canScroll = categoryStrip.scrollWidth > categoryStrip.clientWidth + 1;
        categoryScrollButtons.forEach((button) => {
            button.hidden = !canScroll;
            button.disabled = button.dataset.categoryScroll === 'left'
                ? categoryStrip.scrollLeft <= 1
                : categoryStrip.scrollLeft + categoryStrip.clientWidth >= categoryStrip.scrollWidth - 1;
        });
    };

    categoryScrollButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const direction = button.dataset.categoryScroll === 'left' ? -1 : 1;
            categoryStrip.scrollBy({ left: direction * Math.max(180, categoryStrip.clientWidth * 0.75), behavior: 'smooth' });
        });
    });
    categoryStrip.addEventListener('scroll', updateCategoryScrollButtons, { passive: true });
    window.addEventListener('resize', updateCategoryScrollButtons);

    const selectedCategory = categoryStrip.querySelector('[aria-current="page"]');
    if (selectedCategory) {
        categoryStrip.scrollLeft = Math.max(0, selectedCategory.offsetLeft - (categoryStrip.clientWidth - selectedCategory.clientWidth) / 2);
    }
    updateCategoryScrollButtons();
}

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
