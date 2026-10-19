// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

/*
 * Realtime (Laravel Reverb) for the print queue — an optional accelerator.
 * With no VITE_REVERB_APP_KEY nothing connects and every page keeps working
 * on its own polling; with it, the Print menu, the "printed / failed" toast
 * and the Print Queue screen update the moment something happens.
 *
 * One Echo connection and one subscription per channel for the whole page,
 * shared by every component through onPrintEvent(). Channels (all private,
 * authorised by routes/channels.php):
 *   print-printers      printer status changed          (print-documents)
 *   print-user.{id}     my own jobs changed             (print-documents)
 *   print-queue         every job changed               (manage-print-queue)
 *
 * Events carry no more than the user could already fetch, and components
 * re-fetch through the normal authorised JSON API on receipt.
 */

import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { reactive } from 'vue';

window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY;

// `connected` lets pages slow their polling down while the socket is healthy
// and fall straight back to fast polling the moment it drops.
export const realtime = reactive({ enabled: !!key, connected: false });

let echo = null;
const subscribed = new Set();
const listeners = { 'print-job.changed': new Set(), 'printers.changed': new Set() };

// A manager's own job arrives on both print-queue and print-user.{id}; collapse
// the pair (and any quick repeat) into one notification.
const recentJobEvents = new Map();
function isDuplicateJobEvent(payload) {
	const id = `${payload.job_id}:${payload.status}`;
	const now = Date.now();
	for (const [k, at] of recentJobEvents) {
		if (now - at > 2000) recentJobEvents.delete(k);
	}
	if (recentJobEvents.has(id)) return true;
	recentJobEvents.set(id, now);
	return false;
}

function connect() {
	if (echo || !realtime.enabled) return echo;

	const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https';
	const port = Number(import.meta.env.VITE_REVERB_PORT) || (scheme === 'https' ? 443 : 80);

	echo = new Echo({
		broadcaster: 'reverb',
		key,
		wsHost: import.meta.env.VITE_REVERB_HOST,
		wsPort: port,
		wssPort: port,
		forceTLS: scheme === 'https',
		enabledTransports: ['ws', 'wss'],
		// Authorise private channels through axios, which already carries the
		// session cookie and XSRF token.
		authorizer: (channel) => ({
			authorize: (socketId, callback) => {
				axios
					.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
					.then((response) => callback(null, response.data))
					.catch((error) => callback(error, null));
			},
		}),
	});

	const connection = echo.connector.pusher.connection;
	realtime.connected = connection.state === 'connected';
	connection.bind('state_change', ({ current }) => {
		realtime.connected = current === 'connected';
	});

	return echo;
}

function listen(channelName, event) {
	const id = `${channelName}|${event}`;
	if (subscribed.has(id) || !connect()) return;
	subscribed.add(id);
	echo.private(channelName).listen(`.${event}`, (payload) => {
		if (event === 'print-job.changed' && isDuplicateJobEvent(payload)) return;
		listeners[event].forEach((fn) => fn(payload));
	});
}

/** For anyone who can print: printer status changes and my own jobs. Safe to call repeatedly. */
export function startPrintRealtime(userId) {
	listen('print-printers', 'printers.changed');
	listen(`print-user.${userId}`, 'print-job.changed');
}

/** For people who manage the queue: every job change. Safe to call repeatedly. */
export function startQueueRealtime() {
	listen('print-queue', 'print-job.changed');
}

/** Subscribe to 'print-job.changed' or 'printers.changed'. Returns an unsubscribe function. */
export function onPrintEvent(event, handler) {
	listeners[event].add(handler);
	return () => listeners[event].delete(handler);
}
