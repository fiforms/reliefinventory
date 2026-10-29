import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// The session timed out under an open page. Logged out (bootstrap/app.php
// marks that 401): go to the login page, which returns here afterwards,
// instead of letting the page show "Unauthenticated." as an error. Still
// logged in by the remember-me cookie but holding the old session's CSRF
// token (419): reload, which picks up a fresh token. Neither request ran,
// and it never settles, so the page doesn't flash an error while leaving.
window.axios.interceptors.response.use(undefined, (error) => {
	if (error.response?.headers?.['x-ri-login-required']) {
		window.location.assign('/login');
		return new Promise(() => {});
	}
	if (error.response?.status === 419) {
		window.location.reload();
		return new Promise(() => {});
	}
	return Promise.reject(error);
});
