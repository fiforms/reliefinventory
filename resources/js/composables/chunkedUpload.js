// Resumable chunked upload client for ChunkedUpload.php's protocol (used by the first-run restore wizard
// and the firmware page). start -> PUT consecutive raw chunks at explicit offsets ->
// complete. The server is the source of truth for how much it has: every response carries `received`, and a
// 409 (a chunk it already has, or one that skipped ahead) says where to carry on from, so a dropped
// connection or a double-sent chunk can never corrupt the file. A failed upload keeps its server-side
// progress and can be resumed with the same file.
import axios from 'axios';
import { computed, reactive } from 'vue';

const MAX_RETRIES = 6;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * @param {string} baseUrl  POST baseUrl starts an upload; baseUrl/{id} is GET status / PUT chunk / DELETE cancel;
 *                          baseUrl/{id}/complete is POST complete.
 */
export function useChunkedUpload(baseUrl) {
	const task = reactive({ id: null, chunkSize: 0, received: 0, size: 0, running: false, cancelled: false, failed: false, file: null });
	const percent = computed(() => (task.size ? Math.floor((task.received / task.size) * 100) : 0));

	async function sendChunks() {
		task.running = true;
		task.failed = false;
		let failures = 0;
		try {
			// Re-sync with the server first: this is what makes "Resume" safe.
			task.received = (await axios.get(`${baseUrl}/${task.id}`)).data.received;
			while (task.received < task.size && !task.cancelled) {
				const blob = task.file.slice(task.received, Math.min(task.received + task.chunkSize, task.size));
				try {
					const { data } = await axios.put(`${baseUrl}/${task.id}`, blob, {
						params: { offset: task.received },
						headers: { 'Content-Type': 'application/octet-stream' },
						transformRequest: [(body) => body],
					});
					task.received = data.received;
					failures = 0;
				} catch (e) {
					if (e.response?.status === 409 && e.response.data?.received !== undefined) {
						task.received = e.response.data.received;
						continue;
					}
					if (e.response && e.response.status < 500 && e.response.status !== 429) throw e; // a refusal won't fix itself
					if (++failures > MAX_RETRIES) throw e;
					await sleep(Math.min(1000 * 2 ** failures, 15000)); // flaky network: back off and retry this chunk
				}
			}
			if (task.cancelled) return false;
			await axios.post(`${baseUrl}/${task.id}/complete`);
			return true;
		} catch (e) {
			task.failed = true;
			throw e;
		} finally {
			task.running = false;
		}
	}

	/** Begin a new upload and send it. Resolves true when the server has the whole file and accepted "complete". */
	async function start(file, extra = {}) {
		const { data } = await axios.post(baseUrl, { ...extra, filename: file.name, size: file.size });
		Object.assign(task, { id: data.upload_id, chunkSize: data.chunk_size, received: 0, size: file.size, cancelled: false, failed: false, file });
		return sendChunks();
	}

	/** Carry on a failed upload with the same file. */
	function resume() {
		task.cancelled = false;
		return sendChunks();
	}

	/** Forget the current upload locally (after a finished one, or when cancelling). */
	function reset() {
		Object.assign(task, { id: null, chunkSize: 0, received: 0, size: 0, running: false, cancelled: false, failed: false, file: null });
	}

	/** Stop, let any in-flight chunk finish, then discard what the server holds. */
	async function cancel() {
		task.cancelled = true;
		const id = task.id;
		while (task.running) await sleep(100);
		try {
			if (id) await axios.delete(`${baseUrl}/${id}`);
		} catch {
			/* already gone (or expired): nothing to clean up */
		}
		reset();
	}

	return { task, percent, start, resume, cancel, reset };
}
