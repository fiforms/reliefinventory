// Audible feedback for barcode scans, synthesized with the Web Audio API (no
// sound files to ship or cache, works offline). A scanner's Enter keypress
// counts as the user gesture browsers require before audio may play; every
// call swallows errors, because a missing/blocked audio device must never get
// in the way of sorting. The on/off preference is per browser (localStorage).

const STORAGE_KEY = 'ri_scan_sounds';

let context = null;

function audio() {
	if (!context) {
		const Ctor = window.AudioContext || window.webkitAudioContext;
		if (!Ctor) return null;
		context = new Ctor();
	}
	if (context.state === 'suspended') context.resume();
	return context;
}

function tone(freq, startAt, seconds, type = 'sine', volume = 0.25) {
	const ctx = audio();
	if (!ctx) return;
	const osc = ctx.createOscillator();
	const gain = ctx.createGain();
	const t0 = ctx.currentTime + startAt;
	osc.type = type;
	osc.frequency.value = freq;
	// short fade in/out so it doesn't click
	gain.gain.setValueAtTime(0.0001, t0);
	gain.gain.exponentialRampToValueAtTime(volume, t0 + 0.01);
	gain.gain.exponentialRampToValueAtTime(0.0001, t0 + seconds);
	osc.connect(gain).connect(ctx.destination);
	osc.start(t0);
	osc.stop(t0 + seconds + 0.02);
}

export function scanSoundsEnabled() {
	try {
		return localStorage.getItem(STORAGE_KEY) !== 'off';
	} catch (e) {
		return true;
	}
}

export function setScanSoundsEnabled(on) {
	try {
		localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
	} catch (e) {
		// preference just won't persist
	}
}

function play(fn) {
	if (!scanSoundsEnabled()) return;
	try {
		fn();
	} catch (e) {
		// audio is a nicety; never let it break a scan
	}
}

/** Barcode matched an item: one short high beep, ready for quantity. */
export function playScanMatched() {
	play(() => tone(1400, 0, 0.12));
}

/** Barcode not in the catalog (the add-item form is opening): one lower, longer tone. */
export function playScanUnknown() {
	play(() => tone(330, 0, 0.28, 'triangle'));
}

/** Misscan or failed lookup: two low tones. */
export function playScanError() {
	play(() => {
		tone(220, 0, 0.14, 'square', 0.18);
		tone(220, 0.2, 0.14, 'square', 0.18);
	});
}
