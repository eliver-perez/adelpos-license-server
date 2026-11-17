'use strict';

const menuButton = document.querySelector('.menu-toggle');
const sidebar = document.querySelector('#sidebar');
menuButton?.addEventListener('click', () => {
	const open = sidebar.classList.toggle('is-open');
	menuButton.setAttribute('aria-expanded', String(open));
});
document.addEventListener('keydown', (event) => {
	if (event.key === 'Escape' && sidebar) {
		sidebar.classList.remove('is-open');
		menuButton?.setAttribute('aria-expanded', 'false');
	}
});
document.addEventListener('click', (event) => {
	if (
		sidebar?.classList.contains('is-open') &&
		!sidebar.contains(event.target) &&
		!menuButton?.contains(event.target)
	) {
		sidebar.classList.remove('is-open');
		menuButton?.setAttribute('aria-expanded', 'false');
	}
});
document.querySelectorAll('[data-go-back]').forEach((button) => button.addEventListener('click', () => history.back()));
document.querySelectorAll('[data-copy]').forEach((button) =>
	button.addEventListener('click', async () => {
		const field = document.getElementById(button.dataset.copy);
		try {
			await navigator.clipboard.writeText(field.value);
			button.textContent = 'Copiado';
		} catch {
			field.focus();
			field.select();
			button.textContent = 'Seleccionado';
		}
	}),
);

const licenseForm = document.querySelector('#license-form');
function updateLicenseForm() {
	if (!licenseForm) return;
	const product = licenseForm.querySelector('[name="product_id"]').value;
	const type = licenseForm.querySelector('[name="license_type"]:checked').value;
	licenseForm.querySelectorAll('[data-product]').forEach((group) => {
		const selected = group.dataset.product === product;
		group.hidden = !selected;
		group.querySelectorAll('input').forEach((input) => {
			input.disabled = !selected;
		});
	});
	licenseForm.querySelectorAll('[data-license-type]').forEach((group) => {
		const selected = group.dataset.licenseType === type;
		group.hidden = !selected;
		group.querySelectorAll('input, select').forEach((input) => {
			input.disabled = !selected;
			input.required = selected;
		});
	});
}
// El plan llena los tres cupos. Si alguno se cambia, la licencia queda personalizada y se avisa.
function updatePlanLimits(fill) {
	if (!licenseForm) return;
	const plan = licenseForm.querySelector('[name="plan_id"]').selectedOptions[0];
	let custom = false;
	licenseForm.querySelectorAll('[data-plan-limit]').forEach((input) => {
		const planValue = plan.dataset[input.name];
		if (fill) {
			input.value = planValue;
		}
		custom = custom || input.value !== planValue;
	});
	licenseForm.querySelector('#custom-notice').hidden = !custom;
}
// Monto de alta: el formulario sugiere el precio del catálogo del plan y del plazo elegidos.
// Es solo una ayuda: el servidor vuelve a calcular la sugerencia y exige el motivo si el monto es otro.
function formatMoney(value) {
	const [whole, cents] = Number(value).toFixed(2).split('.');
	return '$' + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + cents + ' MXN';
}
function suggestedIssueAmount() {
	const type = licenseForm.querySelector('[name="license_type"]:checked').value;
	if (type === 'trial') {
		return { amount: '0.00', note: 'Una licencia de prueba no tiene costo: su monto es 0.' };
	}
	if (!licenseForm.querySelector('#custom-notice').hidden) {
		return {
			amount: null,
			note: 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.',
		};
	}
	const term = type === 'perpetual' ? 'perpetual' : licenseForm.querySelector('[name="billing_period"]').value;
	const price =
		licenseForm.querySelector('[name="plan_id"]').selectedOptions[0].dataset[
			'price' + term[0].toUpperCase() + term.slice(1)
		];
	if (!price) {
		return { amount: null, note: 'El plan no tiene precio para este plazo. Escribe el monto cobrado.' };
	}
	return { amount: price, note: 'Precio del catálogo: ' + formatMoney(price) + '.' };
}
function updateIssueAmount() {
	if (!licenseForm) return;
	const amount = licenseForm.querySelector('#amount');
	const reason = licenseForm.querySelector('#amount_reason');
	const suggestion = suggestedIssueAmount();
	const type = licenseForm.querySelector('[name="license_type"]:checked').value;
	if (!amount.dataset.touched || type === 'trial') {
		amount.value = suggestion.amount ?? '';
	}
	amount.readOnly = type === 'trial';
	licenseForm.querySelector('#price-note').textContent = suggestion.note;
	// El motivo solo se escribe cuando el monto es distinto del sugerido.
	const changed = suggestion.amount !== null && Number(amount.value) !== Number(suggestion.amount);
	reason.disabled = !changed;
	reason.required = changed;
	if (!changed) {
		reason.value = '';
	}
}
licenseForm?.querySelector('[name="plan_id"]').addEventListener('change', () => {
	updatePlanLimits(true);
	updateIssueAmount();
});
licenseForm?.querySelectorAll('[data-plan-limit]').forEach((input) =>
	input.addEventListener('input', () => {
		updatePlanLimits(false);
		updateIssueAmount();
	}),
);
updatePlanLimits(true);
licenseForm?.querySelector('[name="product_id"]').addEventListener('change', updateLicenseForm);
licenseForm?.querySelectorAll('[name="license_type"]').forEach((input) =>
	input.addEventListener('change', () => {
		updateLicenseForm();
		updateIssueAmount();
	}),
);
licenseForm?.querySelector('[name="billing_period"]').addEventListener('change', updateIssueAmount);
licenseForm?.querySelector('#amount').addEventListener('input', (event) => {
	event.target.dataset.touched = '1';
	updateIssueAmount();
});
updateLicenseForm();
updateIssueAmount();

document.querySelectorAll('.feature-grid').forEach((group) => {
	group.addEventListener('change', (event) => {
		if (!event.target.matches('input[type="checkbox"]')) return;
		const inputs = [...group.querySelectorAll('input[type="checkbox"]')];
		const visited = new Set();
		function enableRequired(input) {
			if (visited.has(input.value)) return;
			visited.add(input.value);
			for (const key of (input.dataset.requires || '').split(',').filter(Boolean)) {
				const required = inputs.find((candidate) => candidate.value === key);
				if (required) {
					required.checked = true;
					enableRequired(required);
				}
			}
		}
		if (event.target.checked) {
			enableRequired(event.target);
		} else {
			let changed = true;
			while (changed) {
				changed = false;
				for (const input of inputs.filter((candidate) => candidate.checked)) {
					const missing = (input.dataset.requires || '')
						.split(',')
						.filter(Boolean)
						.some((key) => !inputs.some((candidate) => candidate.value === key && candidate.checked));
					if (missing) {
						input.checked = false;
						changed = true;
					}
				}
			}
		}
	});
});
document.querySelectorAll('form[data-confirm]').forEach((form) => {
	form.addEventListener('submit', (event) => {
		if (!window.confirm(form.dataset.confirm)) event.preventDefault();
	});
});
