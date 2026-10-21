document.addEventListener('click', (event) => {
    const addOptionButton = event.target.closest('[data-add-option-row]');
    if (addOptionButton) {
        const form = addOptionButton.closest('[data-options-form]');
        const list = form?.querySelector('[data-option-list]');
        const template = form?.querySelector('[data-option-row-template]');
        if (form && list && template) {
            const index = Number.parseInt(form.dataset.nextIndex ?? '0', 10);
            list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
            form.dataset.nextIndex = String(index + 1);
        }
    }

    const quantityButton = event.target.closest('[data-quantity-change]');
    if (quantityButton) {
        const card = quantityButton.closest('[data-product-card]');
        const quantityInput = card?.querySelector('[data-product-quantity]');
        if (quantityInput) {
            const delta = Number(quantityButton.dataset.quantityChange);
            quantityInput.value = String(Math.max(0, Math.min(20, Number(quantityInput.value || 0) + delta)));
            quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
});

document.addEventListener('input', (event) => {
    if (event.target.matches('[data-product-quantity]')) {
        updateCustomerCart(event.target.closest('[data-customer-menu]'));
    }
});

document.addEventListener('submit', (event) => {
    const menu = event.target.closest('[data-customer-menu]');
    const sparseForm = event.target.closest('[data-sparse-items]');
    if (!menu && !sparseForm) return;

    const cards = (menu ?? sparseForm).querySelectorAll('[data-product-card]');
    const selectedLines = [...cards].filter((card) => {
        const quantity = Number.parseInt(card.querySelector('[data-product-quantity]')?.value ?? '0', 10) || 0;
        return quantity > 0;
    }).length;
    const error = (menu ?? sparseForm).querySelector(menu ? '[data-cart-error]' : '[data-sparse-items-error]');
    if (selectedLines > 30) {
        event.preventDefault();
        if (error) {
            error.textContent = 'Bir siparişte en fazla 30 farklı ürün seçebilirsiniz.';
            error.classList.remove('hidden');
        }
        return;
    }

    if (menu) for (const card of cards) {
        const quantity = Number.parseInt(card.querySelector('[data-product-quantity]')?.value ?? '0', 10) || 0;
        if (quantity === 0) continue;

        for (const group of card.querySelectorAll('[data-option-group]')) {
            const minimum = Number(group.dataset.minSelections ?? 0);
            const selected = group.querySelector('[data-option-single]')
                ? (group.querySelector('[data-option-single]').value ? 1 : 0)
                : group.querySelectorAll('[data-option-checkbox]:checked').length;
            if (selected < minimum) {
                event.preventDefault();
                const message = group.querySelector('[data-option-limit-message]');
                if (message) {
                    message.textContent = `En az ${minimum} seçenek seçin.`;
                    message.classList.remove('hidden');
                }
                const details = group.closest('details');
                if (details) details.open = true;
                group.scrollIntoView({ behavior: 'smooth', block: 'center' });
                break;
            }
        }
        if (event.defaultPrevented) break;
    }

    if (event.defaultPrevented) return;

    for (const card of cards) {
        const quantityInput = card.querySelector('[data-product-quantity]');
        const quantity = Number.parseInt(quantityInput?.value ?? '0', 10) || 0;
        if (quantity > 0) continue;
        if (quantityInput) quantityInput.disabled = true;
        card.querySelectorAll('[name^="options["]').forEach((input) => { input.disabled = true; });
    }
});

document.addEventListener('change', (event) => {
    const menu = event.target.closest('[data-customer-menu]');
    if (!menu) return;

    const optionGroup = event.target.closest('[data-option-group]');
    let exceededMaximum = false;
    const checkbox = event.target.closest('[data-option-checkbox]');
    if (checkbox && checkbox.checked) {
        const maximum = Number(optionGroup?.dataset.maxSelections ?? 0);
        const checked = optionGroup?.querySelectorAll('[data-option-checkbox]:checked').length ?? 0;
        if (maximum > 0 && checked > maximum) {
            checkbox.checked = false;
            exceededMaximum = true;
            const message = optionGroup?.querySelector('[data-option-limit-message]');
            if (message) {
                message.textContent = `En fazla ${maximum} seçenek seçebilirsiniz.`;
                message.classList.remove('hidden');
            }
        }
    }

    if (optionGroup && !exceededMaximum) {
        const minimum = Number(optionGroup.dataset.minSelections ?? 0);
        const maximum = Number(optionGroup.dataset.maxSelections ?? 0);
        const selected = optionGroup.querySelector('[data-option-single]')
            ? (optionGroup.querySelector('[data-option-single]').value ? 1 : 0)
            : optionGroup.querySelectorAll('[data-option-checkbox]:checked').length;
        if (selected >= minimum && (!maximum || selected <= maximum)) {
            optionGroup.querySelector('[data-option-limit-message]')?.classList.add('hidden');
        }
    }

    updateCustomerCart(menu);
});

function updateCustomerCart(menu) {
    if (!menu) return;

    let itemCount = 0;
    let totalCents = 0;
    menu.querySelectorAll('[data-product-card]').forEach((card) => {
        const quantity = Math.max(0, Math.min(20, Number.parseInt(card.querySelector('[data-product-quantity]')?.value ?? '0', 10) || 0));
        const basePrice = Math.round(Number(card.dataset.basePrice ?? 0) * 100);
        let optionPrice = 0;
        card.querySelectorAll('[data-option-single]').forEach((select) => {
            optionPrice += Math.round(Number(select.selectedOptions[0]?.dataset.priceDelta ?? 0) * 100);
        });
        card.querySelectorAll('[data-option-checkbox]:checked').forEach((input) => {
            optionPrice += Math.round(Number(input.dataset.priceDelta ?? 0) * 100);
        });

        const unitPrice = basePrice + optionPrice;
        itemCount += quantity;
        totalCents += unitPrice * quantity;

        const unitLabel = card.querySelector('[data-current-unit-price]');
        if (unitLabel) unitLabel.textContent = formatLira(unitPrice);
        const lineLabel = card.querySelector('[data-current-line-total]');
        if (lineLabel) lineLabel.textContent = formatLira(unitPrice * quantity);
    });

    menu.querySelectorAll('[data-cart-count]').forEach((node) => { node.textContent = String(itemCount); });
    menu.querySelectorAll('[data-cart-total]').forEach((node) => { node.textContent = formatLira(totalCents); });
    menu.querySelectorAll('[data-submit-order]').forEach((button) => { button.disabled = itemCount === 0; });
}

function formatLira(cents) {
    return `₺${new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100)}`;
}

document.querySelectorAll('[data-customer-menu]').forEach(updateCustomerCart);
