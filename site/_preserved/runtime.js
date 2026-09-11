/* Static Russian text captured from the original Russian-language website. */
(() => {
  'use strict';
  const script = document.currentScript;
  const source = script && script.dataset.translations;
  if (source) fetch(source).then(r => r.json()).then(data => {
    let pending = data.patches || [];
    let running = false;
    const apply = () => {
      if (running) return;
      running = true;
      pending = pending.filter(p => {
        let el;
        try { el = document.evaluate(p.xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue; } catch (_) { return false; }
        if (!el) return true;
        el.innerHTML = p.html.replaceAll('https://kerdiss.com', (document.querySelector('meta[name=site-base]')?.content || '/').replace(/\/$/, ''));
        return false;
      });
      running = false;
      if (!pending.length) observer.disconnect();
    };
    const observer = new MutationObserver(() => queueMicrotask(apply));
    observer.observe(document.body, {childList:true,subtree:true});
    apply();
    setTimeout(() => observer.disconnect(), 15000);
  }).catch(() => {});
  // Avoid claiming an enquiry was sent when the original WordPress backend is unavailable.
  const showUnavailable = (form, event) => {
    if (window.ursarSubmitEnquiry && window.ursarSubmitEnquiry(form, event)) return;
    if (!form.matches('form') || form.matches('.woocommerce-ordering,form[method="get"],form[method="GET"]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    let status = form.querySelector('[data-preserved-form-status]');
    if (!status) {
      status = document.createElement('p');
      status.dataset.preservedFormStatus = '';
      status.setAttribute('role','status');
      status.style.cssText = 'margin:16px 0 0;font:14px/1.5 Arial,sans-serif;color:inherit';
      form.appendChild(status);
    }
    status.textContent = 'Отправка через форму пока недоступна. Напишите нам: info@ursar.ru или WhatsApp +7 926 757 79 79.';
  };
  // Some WordPress plugins send AJAX on button clicks before a submit event.
  // Capture the click at window level to stop those handlers as well.
  window.addEventListener('click', event => {
    const button = event.target.closest('button,input[type="submit"],input[type="image"]');
    if (button && button.form && (button.type === 'submit' || button.type === 'image')) {
      showUnavailable(button.form, event);
    }
  }, true);
  window.addEventListener('submit', event => showUnavailable(event.target, event), true);
})();

/* Original WooCommerce catalogue orders, preserved without PHP. */
(() => {
  if (document.getElementById('ursar-public-config')) return;
  const select = document.querySelector('select.orderby');
  const grid = document.querySelector('ul.products');
  if (!select || !grid) return;
  const orders = fetch('/_preserved/orders.json').then(r => r.json());
  const apply = async (order, updateUrl) => {
    const all = await orders;
    const siteBase = document.querySelector('meta[name=site-base]')?.content || '/';
    const route = '/' + location.pathname.slice(siteBase.length);
    const ids = all[route]?.[order];
    if (!ids) return;
    const children = Array.from(grid.children);
    const byId = new Map(children.map(el => [el.querySelector('[data-product_id]')?.dataset.product_id, el]));
    for (const id of ids) if (byId.has(id)) grid.appendChild(byId.get(id));
    if (updateUrl) {
      const url = new URL(location.href);
      url.searchParams.set('orderby', order);
      history.replaceState(null, '', url);
    }
  };
  document.addEventListener('change', e => {
    if (e.target !== select) return;
    e.preventDefault(); e.stopImmediatePropagation();
    apply(select.value, true).catch(() => {});
  }, true);
  const requested = new URLSearchParams(location.search).get('orderby');
  if (requested) { select.value = requested; apply(requested, false).catch(() => {}); }
})();
