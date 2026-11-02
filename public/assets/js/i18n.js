/* =====================================================================
   assets/js/i18n.js — wika ng screen: English (default) o Tagalog.
   Pinipili ng bawat user sa dropdown ng pangalan niya (naka-save sa account: users.language).

   PAANO GUMAGANA
   - Hindi binabago ang mga screen (core.js at modules). Pagka-render ng page, isinasalin ng
     I18n ang mga nakikitang teksto: text, placeholder, title at aria-label.
   - Diksiyunaryo: assets/js/i18n/tl.js (English -> Tagalog) at assets/js/i18n/en.js (para sa
     ilang tekstong Tagalog sa source na dapat English kapag English ang napili).
     May dalawang uri: eksaktong parirala, at pattern (regex) para sa mensaheng may numero o pangalan.
   - HINDI isinasalin (ayon sa PDF at Excel ng kumpanya): mga pangalan ng page sa menu, mga status (Pre-Assigned, Completed ...),
     truck types, roles, mga pangalan ng formula (SSS, PhilHealth ...), at mga printout
     (invoice, payslip, payment voucher, printed report) na nasa #printArea.
   - Para hindi isalin ang isang bahagi: lagyan ng class="no-i18n".
   Para magdagdag ng salin: idagdag sa assets/js/i18n/tl.js. Walang ibang kailangang baguhin.
   ===================================================================== */
'use strict';
const I18n = {
  lang: 'en', on: false, dicts: {}, pats: {}, fragMap: {}, fragRe: {}, cache: new Map(), obs: null,
  missing: null, // para sa pag-check: I18n.missing = new Set() para makita ang tekstong wala pang salin
  // Hindi isinasalin: printouts, code, at ang mga pangalan ng page (sidebar, breadcrumb, page title)
  // para tugma sa mga guide at sa training (halimbawa "Dispatch Board", "Billing & Collections").
  SKIP: '#printArea, script, style, textarea, code, pre, .no-i18n, .sidebar, #bcTitle, .page-title',

  /** Magdagdag ng salin. exact = {English: salin}; patterns = [[RegExp, palit o function], ...] */
  add(lang, exact = {}, patterns = []) {
    this.dicts[lang] = Object.assign(this.dicts[lang] || {}, exact);
    this.pats[lang] = (this.pats[lang] || []).concat(patterns);
    this.cache.clear();
  },

  /**
   * Mga parirala na isinasalin kahit nasa loob ng mas mahabang mensahe (halimbawa
   * "Fund for TRP-2026-0005 was approved and is Ready for Release."). Pinakamahaba muna; hindi
   * tumatama sa gitna ng salita.
   */
  frags(lang, map) {
    this.fragMap[lang] = Object.assign(this.fragMap[lang] || {}, map);
    const esc = (k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const keys = Object.keys(this.fragMap[lang]).sort((a, b) => b.length - a.length);
    this.fragRe[lang] = keys.length ? new RegExp(keys.map(k => (/^[A-Za-z0-9]/.test(k) ? '(?<![A-Za-z0-9])' : '') + esc(k) + (/[A-Za-z0-9]$/.test(k) ? '(?![A-Za-z0-9])' : '')).join('|'), 'g') : null;
    this.cache.clear();
  },

  /** Isalin ang isang teksto (kung walang salin, ibabalik ang orihinal). */
  t(s) {
    if (!this.on || s === null || s === undefined) return s;
    const str = String(s);
    if (!/[A-Za-zñÑ]{2}/.test(str)) return str;
    const key = this.lang + '\u0001' + str;
    if (this.cache.has(key)) return this.cache.get(key);
    const m = str.match(/^(\s*)([\s\S]*?)(\s*)$/);
    const core = m[2].replace(/\s+/g, ' ');
    const dict = this.dicts[this.lang] || {};
    let out = Object.prototype.hasOwnProperty.call(dict, core) ? dict[core] : undefined;
    if (out === undefined) {
      for (const [re, to] of this.pats[this.lang] || []) {
        if (re.test(core)) { out = core.replace(re, to); break; }
      }
    }
    if (out === undefined && this.fragRe[this.lang]) {
      const map = this.fragMap[this.lang];
      const f = core.replace(this.fragRe[this.lang], (x) => map[x] ?? x);
      if (f !== core) out = f;
    }
    if (out === undefined && this.missing) this.missing.add(core);
    const res = out === undefined ? str : m[1] + out + m[3];
    if (this.cache.size > 8000) this.cache.clear();
    this.cache.set(key, res);
    return res;
  },

  node(n) {
    const p = n.parentElement;
    if (!p || p.closest(this.SKIP)) return;
    const v = n.nodeValue, t = this.t(v);
    if (t !== v) n.nodeValue = t;
  },
  attrs(el) {
    if (!el.getAttribute || el.closest(this.SKIP)) return;
    for (const a of ['placeholder', 'title', 'aria-label']) {
      const v = el.getAttribute(a);
      if (v) { const t = this.t(v); if (t !== v) el.setAttribute(a, t); }
    }
  },
  tree(root) {
    if (!this.on || !root) return;
    if (root.nodeType === 3) return this.node(root);
    if (root.nodeType !== 1 || root.closest(this.SKIP)) return;
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const list = []; let n;
    while ((n = w.nextNode())) list.push(n);
    list.forEach(x => this.node(x));
    this.attrs(root);
    root.querySelectorAll('[placeholder],[title],[aria-label]').forEach(el => this.attrs(el));
  },

  /** Simulan (o palitan) ang wika. Isinasalin ang buong page at ang mga bagong lalabas. */
  start(lang) {
    this.lang = lang === 'tl' ? 'tl' : 'en';
    this.on = true;
    document.documentElement.lang = this.lang === 'tl' ? 'tl' : 'en';
    ['confirm', 'prompt', 'alert'].forEach(f => {
      if (!window['_i18n_' + f]) window['_i18n_' + f] = window[f].bind(window);
      window[f] = (msg, ...rest) => window['_i18n_' + f](this.t(msg), ...rest);
    });
    this.tree(document.body);
    if (!this.obs) {
      this.obs = new MutationObserver(list => {
        for (const m of list) {
          if (m.type === 'attributes') this.attrs(m.target);
          else m.addedNodes.forEach(n => this.tree(n));
        }
      });
      this.obs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label'] });
    }
  },
};
