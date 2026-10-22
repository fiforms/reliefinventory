// Types a camera-scanned code into a field exactly the way a keyboard-wedge scanner would: select the field's contents,
// then one keydown / (insert + input) / keyup per character, back to back, then Enter. Every page's existing scan logic
// (Qty burst detection, SearchSelect's scanDigits, modal stray-scan parking, sounds) is keyed off those events, so the
// camera needs no handlers of its own. See SCANNING_INTERFACE_NOTES.md "Camera scanning".

const TEXT_TYPES = ['text', 'search', 'tel', 'url', 'email', 'password', ''];

export function isTextField(el) {
	if (!el || el.disabled || el.readOnly) return false;
	if (el.tagName === 'TEXTAREA') return true;
	return el.tagName === 'INPUT' && TEXT_TYPES.includes((el.getAttribute('type') || '').toLowerCase());
}

function key(el, type, k) {
	const event = new KeyboardEvent(type, { key: k, bubbles: true, cancelable: true });
	el.dispatchEvent(event);
	return event;
}

// Returns false if there was no usable field to type into.
export function injectScan(code, preferred) {
	const text = String(code ?? '').trim();
	const active = document.activeElement;
	const el = isTextField(preferred) ? preferred : (isTextField(active) ? active : null);
	if (!text || !el) return false;

	el.focus();
	el.select();

	for (const ch of text) {
		const down = key(el, 'keydown', ch);
		if (!down.defaultPrevented) {
			const start = el.selectionStart ?? el.value.length;
			const end = el.selectionEnd ?? el.value.length;
			el.value = el.value.slice(0, start) + ch + el.value.slice(end);
			el.setSelectionRange?.(start + 1, start + 1);
			el.dispatchEvent(new Event('input', { bubbles: true }));
		}
		key(el, 'keyup', ch);
	}
	key(el, 'keydown', 'Enter');
	key(el, 'keyup', 'Enter');
	return true;
}
