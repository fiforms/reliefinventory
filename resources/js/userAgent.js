// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

// Lightweight, dependency-free UA summary ("Safari 17.5 on macOS 10.15.7")
// for display — the raw string stays available (tap to expand) for
// whenever the short form isn't enough to tell devices apart. Shared
// between ActiveSessions.vue (login history) and FeedbackReports.vue (bug
// report triage), both of which show a human-readable browser/OS summary
// of the same raw user_agent string.
// Windows NT 10.0 covers both Windows 10 and 11 (browsers don't expose
// which); we label it "Windows 10" since that's what most UA parsers do.
export function uaSummary(ua) {
	if (!ua) return '—';

	const match1 = (re) => ua.match(re)?.[1];
	const majorOf = (v) => v?.split('.')[0];

	let os = 'Unknown OS';
	let osVersion = '';
	if (/iPhone|iPad|iPod/.test(ua)) {
		os = 'iOS';
		osVersion = match1(/OS (\d+[_.]\d+(?:[_.]\d+)?) like Mac OS X/)?.replace(/_/g, '.');
	} else if (/Android/.test(ua)) {
		os = 'Android';
		osVersion = match1(/Android (\d+(?:\.\d+)*)/);
	} else if (/Mac OS X/.test(ua)) {
		os = 'macOS';
		osVersion = match1(/Mac OS X (\d+[_.]\d+(?:[_.]\d+)?)/)?.replace(/_/g, '.');
	} else if (/Windows/.test(ua)) {
		os = 'Windows';
		const windowsVersions = { '10.0': '10', '6.3': '8.1', '6.2': '8', '6.1': '7' };
		const nt = match1(/Windows NT (\d+\.\d+)/);
		osVersion = windowsVersions[nt] || nt || '';
	} else if (/Linux/.test(ua)) {
		os = 'Linux';
	}

	let browser = 'Unknown browser';
	let browserVersion = '';
	if (/Edg\//.test(ua)) {
		browser = 'Edge';
		browserVersion = majorOf(match1(/Edg\/(\d+(?:\.\d+)*)/));
	} else if (/OPR\//.test(ua)) {
		browser = 'Opera';
		browserVersion = majorOf(match1(/OPR\/(\d+(?:\.\d+)*)/));
	} else if (/CriOS\//.test(ua)) {
		browser = 'Chrome';
		browserVersion = majorOf(match1(/CriOS\/(\d+(?:\.\d+)*)/));
	} else if (/Chrome\//.test(ua)) {
		browser = 'Chrome';
		browserVersion = majorOf(match1(/Chrome\/(\d+(?:\.\d+)*)/));
	} else if (/FxiOS\//.test(ua)) {
		browser = 'Firefox';
		browserVersion = majorOf(match1(/FxiOS\/(\d+(?:\.\d+)*)/));
	} else if (/Firefox\//.test(ua)) {
		browser = 'Firefox';
		browserVersion = majorOf(match1(/Firefox\/(\d+(?:\.\d+)*)/));
	} else if (/Version\/.*Safari\//.test(ua)) {
		browser = 'Safari';
		browserVersion = match1(/Version\/(\d+(?:\.\d+)*)/);
	} else if (/Safari\//.test(ua)) {
		browser = 'Safari';
	}

	const browserLabel = browserVersion ? `${browser} ${browserVersion}` : browser;
	const osLabel = osVersion ? `${os} ${osVersion}` : os;

	return `${browserLabel} on ${osLabel}`;
}
