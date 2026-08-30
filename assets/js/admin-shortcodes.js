/**
 * Copy-to-clipboard for the Shortcodes settings tab.
 *
 * ES5 only and no build step, like every other script in this plugin.
 *
 * ---------------------------------------------------------------------------
 * THREE WAYS TO COPY, IN ORDER, AND A FOURTH THAT ALWAYS WORKS
 * ---------------------------------------------------------------------------
 *
 * 1. navigator.clipboard.writeText — the modern API. It does not exist at all
 *    on an insecure origin, which is not an edge case: plenty of stores are
 *    administered over plain http, and a staging site on http almost always is.
 * 2. document.execCommand('copy') over a temporary textarea — deprecated, and
 *    still the only thing that works in case 1.
 * 3. Selecting the shortcode text and telling the owner to press Ctrl+C or
 *    Command+C, since this branch cannot know which platform it is on. Not a
 *    failure message; it is a working instruction.
 *
 * The fourth is the markup itself: the tag is printed in a visible <code>
 * element whether or not this file ever loads, so an owner with JavaScript off
 * can still read it and select it by hand. That is why nothing here creates the
 * shortcode text — it only copies text that is already on the page.
 */
(function () {
	'use strict';

	/**
	 * How long the "Copied" confirmation stays on screen, in milliseconds.
	 */
	var FEEDBACK_MS = 2000;

	/**
	 * Put a short message in the button's feedback span.
	 *
	 * @param {HTMLElement} button  The button that was pressed.
	 * @param {string}      message Text to announce. Set as textContent, never
	 *                              as HTML — it comes from a data attribute the
	 *                              server wrote, and this keeps it that way even
	 *                              if a translation ever contains markup.
	 * @return {void}
	 */
	function announce(button, message) {
		var span = button.parentNode
			? button.parentNode.querySelector('.fcbo-copy-feedback')
			: null;

		if (!span) {
			return;
		}

		span.textContent = message;

		window.setTimeout(function () {
			span.textContent = '';
		}, FEEDBACK_MS);
	}

	/**
	 * Select the shortcode text sitting beside this button.
	 *
	 * The last resort, and the reason it is worth having: a selected string is
	 * one keystroke from the clipboard, so an owner on a browser where neither
	 * copy API works is inconvenienced rather than stuck.
	 *
	 * @param {HTMLElement} button
	 * @return {void}
	 */
	function selectSnippet(button) {
		var code = button.parentNode ? button.parentNode.querySelector('code') : null;

		if (!code || !window.getSelection || !document.createRange) {
			return;
		}

		var range = document.createRange();
		range.selectNodeContents(code);

		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
	}

	/**
	 * Copy a string using the old execCommand path.
	 *
	 * The textarea is positioned off screen rather than hidden: a display:none
	 * or visibility:hidden element cannot be selected, so the copy silently
	 * does nothing.
	 *
	 * @param {string} text
	 * @return {boolean} Whether the copy actually happened.
	 */
	function copyWithExecCommand(text) {
		if (!document.execCommand) {
			return false;
		}

		var field = document.createElement('textarea');

		field.value = text;
		field.setAttribute('readonly', 'readonly');
		field.style.position = 'absolute';
		field.style.left = '-9999px';

		document.body.appendChild(field);
		field.select();

		var copied = false;

		try {
			copied = document.execCommand('copy');
		} catch (e) {
			copied = false;
		}

		document.body.removeChild(field);

		return copied;
	}

	/**
	 * Handle one press of a copy button.
	 *
	 * @param {HTMLElement} button
	 * @return {void}
	 */
	function copy(button) {
		var text = button.getAttribute('data-fcbo-copy');
		var done = button.getAttribute('data-fcbo-copied') || 'Copied';
		var manual = button.getAttribute('data-fcbo-manual') || '';

		if (!text) {
			return;
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(
				function () {
					announce(button, done);
				},
				function () {
					// Resolved permissions can still be refused — a denied
					// clipboard permission rejects here rather than throwing at
					// the call site, so the fallback has to live in this branch
					// too.
					if (copyWithExecCommand(text)) {
						announce(button, done);

						return;
					}

					selectSnippet(button);
					announce(button, manual);
				}
			);

			return;
		}

		if (copyWithExecCommand(text)) {
			announce(button, done);

			return;
		}

		selectSnippet(button);
		announce(button, manual);
	}

	/**
	 * Wire every copy button on the screen.
	 *
	 * One delegated listener rather than one per button: the cards are rendered
	 * server side and never change, but a single listener is cheaper and does
	 * not care how many shortcodes the registry grows to.
	 *
	 * @return {void}
	 */
	function init() {
		document.addEventListener('click', function (event) {
			var target = event.target;

			if (!target || !target.classList || !target.classList.contains('fcbo-copy-button')) {
				return;
			}

			event.preventDefault();

			copy(target);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
