'use strict';
(() => {
  let dirty = false;
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('turbo:before-visit', event => { if (dirty && !window.confirm('Votre préparation contient des modifications non enregistrées. Quitter cette page ?')) event.preventDefault(); });
  const init = () => {
    const form = document.getElementById('tender-preparation');
    if (form && !form.dataset.bound) {
      form.dataset.bound = '1'; dirty = false;
      const status = form.querySelector('[data-save-status]');
      const progress = document.querySelector('[data-preparation-progress]');
      const currency = value => new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(value);
      let costIndex = form.querySelectorAll('[data-cost-row]').length;
      let docIndex = form.querySelectorAll('[data-document-row]').length;
      const totals = () => {
        let costCents = 0n, priceCents = 0n;
        const scaled = (value, places) => {
          if (!/^\d+(?:\.\d+)?$/.test(value)) return 0n;
          const [whole, fraction = ''] = value.split('.');
          return BigInt(whole) * 10n ** BigInt(places) + BigInt(fraction.padEnd(places, '0').slice(0, places));
        };
        form.querySelectorAll('[data-cost-row]').forEach(row => {
          const value = name => row.querySelector(`[data-cost-field="${name}"]`).value;
          const quantity = scaled(value('quantity'), 3);
          costCents += (quantity * scaled(value('cost'), 2) + 500n) / 1000n;
          priceCents += (quantity * scaled(value('price'), 2) + 500n) / 1000n;
        });
        const cost = Number(costCents), price = Number(priceCents);
        form.querySelector('[data-total-cost]').textContent = currency(cost / 100);
        form.querySelector('[data-total-price]').textContent = currency(price / 100);
        form.querySelector('[data-total-margin]').textContent = currency((price - cost) / 100);
        form.querySelector('[data-total-margin]').classList.toggle('tender-negative', price < cost);
        form.querySelector('[data-total-rate]').textContent = price > 0 ? `${((price - cost) / price * 100).toLocaleString('fr-FR', {maximumFractionDigits: 1})} % du prix de vente` : 'Taux non calculable';
        const tasks = form.querySelectorAll('[data-task-done]');
        progress.textContent = `${form.querySelectorAll('[data-task-done]:checked').length} / ${tasks.length} actions terminées`;
      };
      const changed = () => { dirty = true; status.textContent = 'Modifications non enregistrées'; totals(); };
      form.addEventListener('input', changed);
      form.addEventListener('change', changed);
      const add = (kind, index, max) => {
        if (form.querySelectorAll(`[data-${kind}-row]`).length >= max) { status.textContent = `Maximum : ${max} lignes.`; return; }
        const fragment = document.getElementById(`tender-${kind}-template`).content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(input => input.name = input.name.replace('__INDEX__', String(index)));
        const focus = fragment.querySelector('input');
        form.querySelector(`[data-${kind}-rows]`).append(fragment);
        focus?.focus(); changed();
      };
      form.addEventListener('click', event => {
        if (event.target.closest('[data-add-cost]')) add('cost', costIndex++, 40);
        if (event.target.closest('[data-add-document]')) add('document', docIndex++, 30);
        if (event.target.closest('[data-remove-row]')) { event.target.closest('[data-cost-row], [data-document-row]').remove(); changed(); }
      });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type=submit]');
        if (button.disabled) return;
        const data = new FormData(form);
        const signature = JSON.stringify([...data]);
        button.disabled = true; status.textContent = 'Enregistrement…';
        try {
          const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
          const result = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
          if (!response.ok || !result?.ok) throw new Error(result?.message || 'Enregistrement non confirmé. Votre saisie est conservée à l’écran. Vérifiez votre connexion et votre session.');
          const unchanged = JSON.stringify([...new FormData(form)]) === signature;
          form.querySelector('[name=revision]').value = result.revision;
          dirty = !unchanged;
          status.textContent = unchanged ? 'Préparation enregistrée · actualisez la page pour voir l’historique.' : 'Enregistrement effectué ; des modifications plus récentes restent à enregistrer.';
          if (unchanged) form.querySelector('[data-source-changes]')?.remove();
        } catch (error) { status.textContent = error.message; }
        finally { button.disabled = false; }
      });
      totals();
    }
    document.querySelectorAll('[data-insight-source]').forEach(link => {
      if (link.dataset.bound) return;
      link.dataset.bound = '1';
      link.addEventListener('click', () => {
        const table = document.getElementById('insight-fields');
        if (table && window.DataTable?.isDataTable(table)) new window.DataTable(table).search(link.dataset.insightSource).draw();
      });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true}); else init();
  document.addEventListener('turbo:load', init);
})();
