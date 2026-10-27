import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Which warehouse this tab belongs to (the shared `warehouse` prop). Every axios request names it — Inertia's
 * visits go through axios too — and the server refuses one that names another warehouse, or (with several
 * warehouses) a change that names none: another tab may have switched this browser to a different warehouse
 * (MULTI_WAREHOUSE.md §3, GuardWarehouseTab).
 */
let currentWarehouse = null;

export function setWarehouse(warehouse) {
    currentWarehouse = warehouse ?? null;
    if (currentWarehouse?.id) {
        window.axios.defaults.headers.common['X-RI-Warehouse'] = currentWarehouse.id;
    }
}

export function currentWarehouseId() {
    return currentWarehouse?.id ?? null;
}

/** `url` with this tab's warehouse added (`_w`), for downloads and other plain links the server should check. */
export function withWarehouse(url) {
    if (!currentWarehouse?.multiple || !url) return url;
    const [path, fragment] = url.split('#');
    const joined = `${path}${path.includes('?') ? '&' : '?'}_w=${encodeURIComponent(currentWarehouse.id)}`;
    return fragment === undefined ? joined : `${joined}#${fragment}`;
}

/** A localStorage key kept per warehouse: printer ids and the like mean something only in one warehouse. */
export function warehouseStorageKey(key) {
    return `${key}${currentWarehouse?.storageSuffix ?? ''}`;
}

// A JSON call refused because the tab is stale: send the tab to the page that explains it (an Inertia visit gets
// the same through its own 409 + X-Inertia-Location handling).
window.axios.interceptors.response.use(undefined, (error) => {
    if (error?.response?.status === 409 && error.response.data?.warehouse_switched && !error.response.headers?.['x-inertia']) {
        window.location.href = '/warehouse-switched';
    }
    return Promise.reject(error);
});
