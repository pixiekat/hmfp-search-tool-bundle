import { Controller } from '@hotwired/stimulus';

/*
 * Live suggestions for the provider search box.
 *
 * ── What this does NOT do ──────────────────────────────────────────────────
 * It does not implement an autocomplete widget. The <input> is attached to a
 * <datalist>, which IS the browser's own combobox: it owns the dropdown, the
 * arrow-key navigation, the Enter and Escape handling, the screen-reader
 * announcements and the platform's visual conventions. All this controller does
 * is keep that list's contents up to date.
 *
 * That division is the whole point. A hand-rolled autocomplete is one of the
 * most reliably inaccessible things on the web — it needs aria-expanded,
 * aria-activedescendant, roving focus, a live region for "8 suggestions
 * available", and it has to not break when someone is using a screen reader's
 * own browse mode. The browser already ships a correct implementation.
 *
 * ── It is an enhancement, not a dependency ─────────────────────────────────
 * With this file absent or broken, the datalist still holds the specialties the
 * server rendered into it, and the form still submits as a plain GET. Nothing
 * here is load-bearing.
 */
export default class extends Controller {
  static values = {
    url: String,
    // Milliseconds of quiet before asking the server. 180ms is about the gap
    // between keystrokes for an average typist: long enough that a word costs
    // one request rather than eight, short enough to feel immediate.
    debounce: { type: Number, default: 180 },
    minLength: { type: Number, default: 2 },
  };

  connect() {
    this.timer = null;
    this.controller = null;
    // Cheap memo of queries already fetched. Backspacing is extremely common
    // and would otherwise re-request something just seen.
    this.cache = new Map();

    this.list = document.getElementById(this.element.getAttribute('list'));

    /*
     * Keep the options the server rendered. They are the no-JavaScript
     * baseline, and they are also what the list should fall back to whenever
     * the query is too short to fetch for — see onInput().
     */
    this.seed = this.list ? Array.from(this.list.children).map((o) => o.value) : [];

    this.onInput = this.onInput.bind(this);
    this.element.addEventListener('input', this.onInput);
  }

  disconnect() {
    this.element.removeEventListener('input', this.onInput);
    clearTimeout(this.timer);
    this.controller?.abort();
  }

  onInput() {
    clearTimeout(this.timer);

    const query = this.element.value.trim();

    if (query.length < this.minLengthValue) {
      /*
       * Restore the seeded list rather than leaving the last fetch in place.
       *
       * Leaving it alone seemed kinder — no flicker while someone edits — but
       * it produces a list that contradicts the box: type "cardio", backspace
       * to "c", and the dropdown still offers cardiology specialties. A
       * suggestion list that does not correspond to what has been typed is
       * worse than an empty one, because it looks authoritative.
       *
       * Falling back to the seed rather than to nothing means an empty box
       * still offers the most common specialties, which is a useful starting
       * point rather than a blank.
       */
      this.render(this.seed.map((value) => ({ value })));
      return;
    }

    this.timer = setTimeout(() => this.fetchSuggestions(query), this.debounceValue);
  }

  async fetchSuggestions(query) {
    if (!this.list) {
      return;
    }

    if (this.cache.has(query)) {
      this.render(this.cache.get(query));
      return;
    }

    /*
     * Abort the previous request before starting another. Without this, a fast
     * typist has several in flight at once and whichever finishes LAST wins —
     * so the list can end up showing suggestions for a prefix the box no longer
     * contains. Aborting makes the newest request authoritative by construction.
     */
    this.controller?.abort();
    this.controller = new AbortController();

    try {
      const response = await fetch(
        `${this.urlValue}?q=${encodeURIComponent(query)}`,
        {
          signal: this.controller.signal,
          headers: { Accept: 'application/json' },
          // Send the session cookie: the endpoint is behind authentication.
          credentials: 'same-origin',
        },
      );

      if (!response.ok) {
        /*
         * Fall back to the seed rather than leaving the previous fetch in
         * place. The search box keeps working and the visitor gets exactly the
         * no-JavaScript experience — which is only true if the list actually
         * holds the seeded options, not a stale result for some earlier query.
         */
        this.render(this.seed.map((value) => ({ value })));
        return;
      }

      const data = await response.json();
      const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];

      this.cache.set(query, suggestions);
      this.render(suggestions);
    } catch (error) {
      // AbortError is the normal path when the user keeps typing, not a fault.
      if (error.name !== 'AbortError') {
        console.warn('Suggestion lookup failed', error);
      }
    }
  }

  render(suggestions) {
    /*
     * Built with createElement and assigned through .value rather than
     * innerHTML. These strings come from the database, and although they are
     * physician and specialty names rather than anything user-authored, an
     * autocomplete that interpolates markup is a needless place to be wrong.
     * Setting .value on an element node cannot introduce markup at all.
     */
    const fragment = document.createDocumentFragment();

    for (const suggestion of suggestions) {
      if (typeof suggestion?.value !== 'string') {
        continue;
      }

      const option = document.createElement('option');
      option.value = suggestion.value;

      /*
       * No `label` attribute, deliberately.
       *
       * It looked like a free way to show the kind — "specialty" beside
       * "Cardiology" — but browsers do not agree on what label means here.
       * Firefox renders the LABEL INSTEAD OF the value, so every row displayed
       * as the word "specialty" and the actual suggestion was invisible.
       * Chrome shows both, Safari shows the value. The only rendering every
       * browser agrees on is value-only.
       *
       * The kind is still in the JSON for anything that wants it; it just is
       * not worth breaking the list in Firefox to surface it here.
       */
      fragment.appendChild(option);
    }

    this.list.replaceChildren(fragment);
  }
}
