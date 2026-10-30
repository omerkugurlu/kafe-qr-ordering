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
    if (details.dataset.pickerInitialized === 'true') return;

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

document.querySelectorAll('[data-category-recommendation-manager]').forEach((manager) => {
    const form = manager.querySelector('[data-category-recommendation-form]');
    const triggerSearch = form?.querySelector('[data-recommendation-trigger-search]');
    const triggerOptions = form?.querySelector('[data-recommendation-trigger-options]');
    const triggerCount = form?.querySelector('[data-recommendation-trigger-count]');
    const options = form?.querySelector('[data-recommendation-product-options]');
    const search = form?.querySelector('[data-recommendation-product-search]');
    const count = form?.querySelector('[data-category-recommendation-count]');
    const message = form?.querySelector('[data-recommendation-message]');
    if (!form || !triggerSearch || !triggerOptions || !triggerCount || !options || !search || !count) return;

    const triggerCheckboxes = [...triggerOptions.querySelectorAll('input[type="checkbox"]')];

    const updateTriggerCount = () => {
        const selectedCount = triggerCheckboxes.filter((checkbox) => checkbox.checked).length;
        triggerCount.textContent = `${selectedCount} kategori seçili`;
    };

    const filter = () => {
        const activeCategory = manager.dataset.targetCategory ?? '';
        const query = search.value.trim().toLocaleLowerCase('tr-TR');
        let visibleCount = 0;
        options.querySelectorAll('[data-recommendation-product-option]').forEach((option) => {
            const matches = (activeCategory === '' || option.dataset.categoryId === activeCategory)
                && option.textContent.trim().toLocaleLowerCase('tr-TR').includes(query);
            option.hidden = !matches;
            if (matches) visibleCount += 1;
        });
        const empty = options.querySelector('[data-recommendation-empty]');
        if (empty) empty.hidden = visibleCount > 0;
        manager.querySelectorAll('[data-recommendation-target-filter]').forEach((button) => {
            const selected = button.dataset.categoryId === activeCategory;
            button.setAttribute('aria-pressed', String(selected));
            button.classList.toggle('border-forest', selected);
            button.classList.toggle('bg-sage', selected);
            button.classList.toggle('text-forest', selected);
            button.classList.toggle('bg-paper', !selected);
            button.classList.toggle('text-ink', !selected);
        });
    };
    const updateCount = () => {
        const selectedCount = options.querySelectorAll('input[type="checkbox"]:checked').length;
        count.textContent = `${selectedCount} / 6 seçili`;
    };
    triggerSearch.addEventListener('input', () => {
        const query = triggerSearch.value.trim().toLocaleLowerCase('tr-TR');
        triggerOptions.querySelectorAll('[data-recommendation-trigger-option]').forEach((option) => {
            option.hidden = !option.textContent.trim().toLocaleLowerCase('tr-TR').includes(query);
        });
    });
    triggerOptions.addEventListener('change', (event) => {
        const checkbox = event.target.closest?.('input[type="checkbox"]');
        if (checkbox?.checked) {
            const selectedIds = new Set([...options.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value));
            let hitLimit = false;
            (checkbox.dataset.suggestionProducts ?? '').split(',').filter(Boolean).forEach((id) => {
                if (selectedIds.has(id)) return;
                if (selectedIds.size >= 6) {
                    hitLimit = true;
                    return;
                }
                selectedIds.add(id);
            });
            options.querySelectorAll('input[type="checkbox"]').forEach((item) => {
                item.checked = selectedIds.has(item.value);
            });
            updateCount();
            if (message) message.textContent = hitLimit ? 'En fazla 6 ürün seçebilirsin.' : '';
        } else if (message) {
            message.textContent = '';
        }
        updateTriggerCount();
    });

    manager.querySelectorAll('[data-recommendation-target-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            manager.dataset.targetCategory = manager.dataset.targetCategory === button.dataset.categoryId ? '' : button.dataset.categoryId;
            filter();
        });
    });
    search.addEventListener('input', filter);
    options.addEventListener('change', (event) => {
        const checkbox = event.target.closest?.('input[type="checkbox"]');
        if (checkbox?.checked && options.querySelectorAll('input[type="checkbox"]:checked').length > 6) {
            checkbox.checked = false;
            if (message) message.textContent = 'En fazla 6 ürün seçebilirsin.';
        } else if (message) {
            message.textContent = '';
        }
        updateCount();
    });
    form.querySelector('[data-recommendation-select-visible]')?.addEventListener('click', () => {
        let hitLimit = false;
        options.querySelectorAll('[data-recommendation-product-option]:not([hidden]) input[type="checkbox"]').forEach((checkbox) => {
            if (checkbox.checked) return;
            if (options.querySelectorAll('input[type="checkbox"]:checked').length >= 6) {
                hitLimit = true;
                return;
            }
            checkbox.checked = true;
        });
        if (message) message.textContent = hitLimit ? 'En fazla 6 ürün seçebilirsin.' : '';
        updateCount();
    });
    form.querySelector('[data-recommendation-clear]')?.addEventListener('click', () => {
        options.querySelectorAll('input[type="checkbox"]:checked').forEach((checkbox) => { checkbox.checked = false; });
        if (message) message.textContent = '';
        updateCount();
    });
    filter();
    updateTriggerCount();
    form.addEventListener('submit', (event) => {
        if (triggerCheckboxes.some((checkbox) => checkbox.checked)) return;
        event.preventDefault();
        if (message) message.textContent = 'En az bir tetikleyici kategori seçmelisin.';
        triggerCheckboxes[0]?.focus();
    });
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

function initPaymentPicker(root) {
    const totalOutput = root.querySelector('[data-payment-total]');
    const countOutput = root.querySelector('[data-payment-selected-summary]');
    const changeOutput = root.querySelector('[data-payment-change]');
    const cashWrap = root.querySelector('[data-cash-received-wrap]');
    const cashInput = root.querySelector('[data-cash-received]');
    const searchInput = root.querySelector('[data-payment-search]');
    const itemSummary = root.querySelector('[data-payment-items-summary]');
    const emptyResults = root.querySelector('[data-payment-empty-results]');
    const itemRows = [...root.querySelectorAll('[data-payment-item]')];
    const methodInputs = [...root.querySelectorAll('input[name="method"]')];
    const modeInputs = [...root.querySelectorAll('[data-payment-mode]')];
    const itemsFieldset = root.querySelector('[data-payment-items-fieldset]');
    const splitPanel = root.querySelector('[data-payment-split-panel]');
    const splitPeopleInputs = [...root.querySelectorAll('[data-split-people]')];
    const splitShareOutput = root.querySelector('[data-split-share]');
    const splitRemainingOutput = root.querySelector('[data-split-remaining]');
    const splitDescription = root.querySelector('[data-split-description]');
    const submit = root.querySelector('[data-payment-submit]');
    const canSubmit = !submit?.disabled;
    const formatter = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const money = (cents) => `₺${formatter.format(cents / 100)}`;

    if (!totalOutput || !submit) {
        return;
    }

    const update = () => {
        let totalCents = 0;
        let selectedQuantity = 0;
        const selectedItems = [];
        const isCash = root.querySelector('input[name="method"]:checked')?.value === 'cash';
        const isSplitMode = root.querySelector('[data-payment-mode]:checked')?.value === 'split';
        const activeSplit = root.dataset.splitActive === 'true';
        const fixedPeopleCount = Number.parseInt(root.dataset.splitCount || '0', 10);
        const paidShares = Number.parseInt(root.dataset.splitPaid || '0', 10);
        const selectedPeopleCount = Number.parseInt(root.querySelector('[data-split-people]:checked')?.value || '0', 10);
        const peopleCount = activeSplit ? fixedPeopleCount : selectedPeopleCount;
        const remainingPeople = Math.max(0, peopleCount - paidShares);

        if (itemsFieldset) {
            itemsFieldset.disabled = isSplitMode;
            itemsFieldset.hidden = isSplitMode;
        }
        if (splitPanel) splitPanel.hidden = !isSplitMode;
        splitPeopleInputs.forEach((input) => {
            input.disabled = !isSplitMode || (activeSplit && input.value !== String(fixedPeopleCount));
        });

        itemRows.forEach((row) => {
            const checkbox = row.querySelector('[data-payment-item-check]');
            const quantityInput = row.querySelector('[data-payment-item-quantity]');
            const quantity = Math.min(
                Number(quantityInput.max || 0),
                Math.max(1, Number.parseInt(quantityInput.value, 10) || 1),
            );

            if (checkbox.checked) {
                quantityInput.disabled = false;
                quantityInput.value = String(quantity);
                totalCents += Math.min(Number(row.dataset.dueCents), Number(row.dataset.unitCents) * quantity);
                selectedQuantity += quantity;
                selectedItems.push(`${quantity}× ${row.querySelector('[data-payment-item-name]').textContent.trim()}`);
            } else {
                quantityInput.disabled = true;
            }
        });

        if (isSplitMode) {
            const splitTargetCents = Number.parseInt(root.dataset.splitTargetCents || root.dataset.splitDueCents || root.dataset.dueCents || '0', 10);
            const paidSplitCents = Number.parseInt(root.dataset.splitPaidCents || '0', 10);
            const remainingSplitCents = Math.max(0, splitTargetCents - paidSplitCents);
            totalCents = remainingPeople > 0 ? Math.ceil(remainingSplitCents / remainingPeople) : 0;
            selectedQuantity = 0;
            countOutput.textContent = remainingPeople > 0 ? `${paidShares + 1} / ${peopleCount} kişi payı` : 'Hesap bölme tamamlandı';
            if (splitShareOutput) splitShareOutput.textContent = money(totalCents);
            if (splitRemainingOutput) splitRemainingOutput.textContent = remainingPeople > 0 ? `Kalan ${remainingPeople} kişi` : 'Bölme tamamlandı';
            if (splitDescription && !activeSplit) splitDescription.textContent = `${peopleCount} kişilik hesap. Son payda kalan kuruş farkı otomatik dengelenir.`;
            itemSummary.textContent = activeSplit
                ? `Kişi başı tahsilat · ${paidShares + 1} / ${peopleCount}`
                : `Toplam hesap ${peopleCount} kişiye bölünüyor.`;
        } else if (splitShareOutput) {
            splitShareOutput.textContent = money(0);
        }

        totalOutput.textContent = money(totalCents);
        if (!isSplitMode) {
            countOutput.textContent = `${selectedQuantity} adet seçildi`;
            itemSummary.textContent = selectedItems.length > 0 ? selectedItems.join(' · ') : 'Ödenecek ürünleri seç.';
        }
        const cashReceivedCents = Math.round((Number(cashInput?.value) || 0) * 100);
        const cashShortfallCents = Math.max(0, totalCents - cashReceivedCents);
        changeOutput.textContent = isCash
            ? (cashShortfallCents > 0
                ? `Eksik: ${money(cashShortfallCents)}`
                : `Para üstü: ${money(cashReceivedCents - totalCents)}`)
            : 'Para üstü: —';
        changeOutput.classList.toggle('text-danger', isCash && cashShortfallCents > 0);
        changeOutput.classList.toggle('text-success', !isCash || cashShortfallCents === 0);
        cashWrap.hidden = !isCash;
        cashInput.disabled = !isCash;
        submit.disabled = !canSubmit || totalCents <= 0 || (isCash && cashShortfallCents > 0) || (isSplitMode && remainingPeople <= 0);
    };

    root.querySelectorAll('[data-payment-item-check]').forEach((checkbox) => {
        checkbox.addEventListener('change', update);
    });
    root.querySelectorAll('[data-payment-item-quantity]').forEach((input) => {
        input.addEventListener('input', update);
        input.addEventListener('change', update);
    });
    cashInput?.addEventListener('input', update);
    methodInputs.forEach((input) => input.addEventListener('change', update));
    modeInputs.forEach((input) => input.addEventListener('change', update));
    splitPeopleInputs.forEach((input) => input.addEventListener('change', update));
    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.trim().toLocaleLowerCase('tr-TR');
        let visibleCount = 0;
        itemRows.forEach((row) => {
            const name = row.querySelector('[data-payment-item-name]').textContent.toLocaleLowerCase('tr-TR');
            row.hidden = query !== '' && !name.includes(query);
            if (!row.hidden) {
                visibleCount += 1;
            }
        });
        if (emptyResults) {
            emptyResults.hidden = visibleCount > 0;
        }
    });
    root.querySelector('[data-payment-select-all]')?.addEventListener('click', () => {
        itemRows.forEach((row) => {
            row.querySelector('[data-payment-item-check]').checked = true;
            row.querySelector('[data-payment-item-quantity]').value = row.dataset.maxQuantity;
        });
        update();
    });
    root.querySelector('[data-payment-clear]')?.addEventListener('click', () => {
        itemRows.forEach((row) => {
            row.querySelector('[data-payment-item-check]').checked = false;
            row.querySelector('[data-payment-item-quantity]').value = '1';
        });
        update();
    });

    update();
}

document.querySelectorAll('[data-payment-picker]').forEach(initPaymentPicker);

Object.assign(window.skykite ?? {}, { openSheet, closeSheet, openDrawer, closeDrawer });
