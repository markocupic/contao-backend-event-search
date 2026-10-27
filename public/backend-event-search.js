/*
 * This file is part of Contao Backend Event Search.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-backend-event-search
 */

(function () {
	'use strict';

	const DEBOUNCE_MS = 250;
	const STORAGE_KEY = 'backend_event_search_query';

	function init(root) {
		if (root.dataset.initialized) {
			return;
		}

		root.dataset.initialized = '1';

		const input = root.querySelector('[data-backend-event-search-input]');
		const status = root.querySelector('[data-backend-event-search-status]');
		const list = root.querySelector('[data-backend-event-search-results]');
		const minLength = parseInt(root.dataset.minLength, 10) || 2;

		let timer = null;
		let controller = null;

		const sprintf = (str, ...args) => args.reduce((s, arg) => s.replace('%d', String(arg)), str);

		const clear = () => {
			list.replaceChildren();
			status.textContent = '';
			root.classList.remove('is-loading');
		};

		const createIconLink = (href, title, icon, modifier) => {
			const a = document.createElement('a');
			a.href = href;
			a.title = title;
			a.className = 'backend-event-search__operation backend-event-search__operation--' + modifier;

			const img = document.createElement('img');
			img.src = icon;
			img.alt = title;
			img.width = 16;
			img.height = 16;
			a.append(img);

			return a;
		};

		const createOperations = (hit) => {
			// Operations provided by another bundle (BuildSearchHitOperationsEvent), e.g. the SAC Event-Tool.
			// The markup is rendered and escaped on the server. Stimulus controllers in it connect automatically.
			if (hit.operationsHtml) {
				const external = document.createElement('div');
				external.className = 'backend-event-search__operations backend-event-search__operations--external';
				external.innerHTML = hit.operationsHtml;

				return external;
			}

			const operations = document.createElement('span');
			operations.className = 'backend-event-search__operations';

			// Event list of the calendar
			operations.append(createIconLink(hit.listUrl, root.dataset.labelOpenList, root.dataset.listIcon, 'list'));

			// Participant list (only available with the Event-Tool)
			if (hit.participantsUrl) {
				operations.append(createIconLink(hit.participantsUrl, root.dataset.labelParticipants, root.dataset.participantsIcon, 'participants'));
			}

			return operations;
		};

		const render = (data) => {
			list.replaceChildren();

			if (!data.hits.length) {
				status.textContent = root.dataset.labelNoResults;
				return;
			}

			status.textContent = data.totalHits > data.hits.length
				? sprintf(root.dataset.labelMoreResults, data.totalHits, data.hits.length)
				: sprintf(root.dataset.labelResults, data.totalHits);

			for (const hit of data.hits) {
				const li = document.createElement('li');
				li.className = 'backend-event-search__hit';

				// Main link: edit the event.
				// The *Html values are escaped on the server, only <mark> tags are added.
				// Without editUrl (e.g. no permission, see BuildSearchHitOperationsEvent) the hit is not linked.
				const link = document.createElement(hit.editUrl ? 'a' : 'span');
				link.className = 'backend-event-search__link';
				link.title = hit.editTitle || root.dataset.labelEdit;

				if (hit.editUrl) {
					link.href = hit.editUrl;
				} else {
					link.classList.add('backend-event-search__link--disabled');
				}

				const title = document.createElement('span');
				title.className = 'backend-event-search__title';
				title.innerHTML = hit.titleHtml;
				link.append(title);

				const meta = document.createElement('span');
				meta.className = 'backend-event-search__meta';

				// The query matched the id of the event
				if (hit.matchedById) {
					const id = document.createElement('span');
					id.className = 'backend-event-search__id';

					const mark = document.createElement('mark');
					mark.textContent = 'ID: ' + hit.id;
					id.append(mark);

					meta.append(id);
				}

				// Release level (Freigabestufe) of the Event-Tool
				if (null !== hit.releaseLevel && undefined !== hit.releaseLevel) {
					const level = document.createElement('span');
					level.className = 'backend-event-search__release-level release-level-' + hit.releaseLevel;
					level.textContent = 'FS: ' + hit.releaseLevel;

					if (hit.releaseLevelTitle) {
						level.title = root.dataset.labelReleaseLevel + ': ' + hit.releaseLevelTitle;
					}

					meta.append(level);
				}

				for (const text of [hit.date, hit.calendarTitle]) {
					if (text) {
						const span = document.createElement('span');
						span.textContent = text;
						meta.append(span);
					}
				}

				if (hit.instructorsHtml) {
					const span = document.createElement('span');
					span.className = 'backend-event-search__instructors';
					span.innerHTML = hit.instructorsHtml;
					meta.append(span);
				}

				link.append(meta);

				for (const snippet of hit.snippets) {
					const span = document.createElement('span');
					span.className = 'backend-event-search__snippet';

					const label = document.createElement('strong');
					label.textContent = snippet.label + ': ';

					const value = document.createElement('span');
					value.innerHTML = snippet.html;

					span.append(label, value);
					link.append(span);
				}

				li.append(link, createOperations(hit));
				list.append(li);
			}
		};

		const search = async (query) => {
			controller?.abort();
			controller = new AbortController();

			root.classList.add('is-loading');

			try {
				const url = new URL(root.dataset.searchUrl, document.baseURI);
				url.searchParams.set('q', query);

				const response = await fetch(url, {
					headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
					credentials: 'same-origin',
					signal: controller.signal,
				});

				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}

				render(await response.json());
			} catch (e) {
				if ('AbortError' === e.name) {
					return;
				}

				list.replaceChildren();
				status.textContent = root.dataset.labelError;
				console.error(e);
			} finally {
				root.classList.remove('is-loading');
			}
		};

		const onInput = () => {
			const query = input.value.trim();

			clearTimeout(timer);

			try {
				sessionStorage.setItem(STORAGE_KEY, query);
			} catch (e) {
				// Storage might be unavailable (private mode)
			}

			// Integers (event ids) may be shorter than the minimum query length
			if ('' === query || (query.length < minLength && !/^\d+$/.test(query))) {
				controller?.abort();
				clear();
				return;
			}

			timer = setTimeout(() => search(query), DEBOUNCE_MS);
		};

		input.addEventListener('input', onInput);

		// Keyboard navigation: arrow keys move through the results, escape clears the search
		root.addEventListener('keydown', (e) => {
			// The operation menus of other bundles handle their own keys (e.g. Escape closes the dropdown)
			if (e.target.closest('.backend-event-search__operations--external')) {
				return;
			}

			const links = [...list.querySelectorAll('a.backend-event-search__link')];
			const index = links.indexOf(document.activeElement);

			if ('ArrowDown' === e.key) {
				e.preventDefault();
				(links[index + 1] ?? links[0])?.focus();
			} else if ('ArrowUp' === e.key) {
				e.preventDefault();
				(index > 0 ? links[index - 1] : input).focus();
			} else if ('Escape' === e.key) {
				input.value = '';
				onInput();
				input.focus();
			}
		});

		// Restore the last query when coming back to the start page
		try {
			const lastQuery = sessionStorage.getItem(STORAGE_KEY);

			if (lastQuery) {
				input.value = lastQuery;
				onInput();
			}
		} catch (e) {
			// Storage might be unavailable (private mode)
		}
	}

	const boot = () => document.querySelectorAll('[data-backend-event-search]').forEach(init);

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Contao 5 back end uses Turbo
	document.addEventListener('turbo:load', boot);
})();
