/* Shared presentation adapter: original controls remain the source of filter values. */
(() => {
  'use strict';
  const start = () => {
    document.querySelectorAll('.pf-filters').forEach(root => {
      const controls = root.querySelectorAll('.filter-control:not(.fcol-actions), .f-item, .row.g-3 > .col-md-4');
      controls.forEach(control => {
        control.classList.add('pf-filter-chip');
        const field = control.querySelector('select, input:not([type="hidden"])');
        const label = control.querySelector('.form-label');
        if (!control.querySelector('.filter-icon')) {
          const icon = document.createElement('span'); icon.className = 'filter-icon';
          const glyph = document.createElement('i');
          const name = field?.id || control.querySelector('input[id]')?.id || '';
          glyph.className = 'bi bi-' + (/Range|Start|End/.test(name) ? 'calendar3' : /Employ/.test(name) ? 'people' : 'truck');
          icon.setAttribute('aria-hidden','true'); icon.append(glyph); control.prepend(icon);
        }
        if (field && label) {
          if (!field.id) field.id = `pf-field-${Math.random().toString(36).slice(2)}`;
          label.id = `${field.id}-label`;
          field.setAttribute('aria-labelledby', label.id);
        }
      });
      const updates = [];
      root.querySelectorAll('select').forEach(select => {
        const label = select.closest('.pf-filter-chip')?.querySelector('.form-label');
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'pf-filter-trigger';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-haspopup', 'dialog');
        select.after(button);
        select.classList.add('pf-filter-source');
        const allValue = Array.from(select.options).find(o => o.value === 'all' || o.value === '')?.value ?? '';
        const options = () => (select.tomselect
          ? Object.values(select.tomselect.options).sort((a,b) => (a.$order || 0) - (b.$order || 0)).map(o => ({value:String(o.value), text:o.text, disabled:o.disabled}))
          : Array.from(select.options, o => ({value:o.value,text:o.text,disabled:o.disabled})))
          .filter(o => o.value !== '' && o.value !== 'all' && o.value !== '__none__' && !o.value.startsWith('['));
        const values = () => {
          const raw = select.tomselect ? select.tomselect.getValue() : select.value;
          if (select.multiple) {
            const selected = select.tomselect ? [].concat(raw) : Array.from(select.selectedOptions, o=>o.value);
            return selected.includes('__none__') ? [] : selected.length ? selected : options().map(o=>o.value);
          }
          if (!raw || raw === 'all') return options().map(o=>o.value);
          if (raw.startsWith('[')) { try { return JSON.parse(raw); } catch { return []; } }
          return [raw];
        };
        const update = () => {
          const chosen = options().filter(o => values().includes(o.value));
          button.textContent = !chosen.length ? 'Aucun' : chosen.length === options().length ? 'Tous' : chosen.length > 1 ? `${chosen.length} sélectionnés` : chosen[0].text;
          button.title = chosen.map(o => o.text).join(', ') || 'Tous';
          button.setAttribute('aria-label', `${label?.textContent.trim() || 'Filtre'} : ${button.textContent}`);
        };
        updates.push(update); update();
        select.addEventListener('change', update);
        // Existing pages also reset controls silently or rebuild their available options.
        new MutationObserver(update).observe(select, {childList:true,subtree:true});
        let popup;
        const close = (focus = false) => {
          if (!popup) return;
          popup.remove(); popup = null; button.setAttribute('aria-expanded','false');
          document.removeEventListener('pointerdown', outside);
          window.removeEventListener('resize', reposition);
          document.removeEventListener('scroll', reposition, true);
          if (focus) button.focus();
        };
        const outside = event => { if (!popup?.contains(event.target) && !button.contains(event.target)) close(); };
        const reposition = () => {
          if (!popup) return;
          const rect = button.getBoundingClientRect();
          const width = Math.min(340, window.innerWidth - 24);
          popup.style.width = `${width}px`;
          popup.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
          const below = window.innerHeight - rect.bottom - 16;
          const above = rect.top - 16;
          const height = Math.min(360, Math.max(below, above));
          popup.style.maxHeight = `${height}px`;
          popup.style.top = `${below >= Math.min(360, above) ? rect.bottom + 8 : Math.max(12, rect.top - Math.min(height, popup.scrollHeight) - 8)}px`;
        };
        const set = selected => {
          const all = selected.length === options().length && selected.length > 0;
          const value = select.multiple ? (all ? [] : selected.length ? selected : ['__none__'])
            : all ? allValue : selected.length === 1 ? selected[0] : JSON.stringify(selected);
          const text = !selected.length ? 'Aucun' : `${selected.length} sélectionnés`;
          // Keep the page's existing request field and reset handlers; encode multi-choice values explicitly.
          for (const v of [].concat(value)) {
            if (select.tomselect) { if (!select.tomselect.options[v]) select.tomselect.addOption({value:v,text}); }
            else if (!Array.from(select.options).some(o=>o.value===v)) select.add(new Option(text,v));
          }
          if (select.tomselect) select.tomselect.setValue(value);
          else {
            for (const option of select.options) option.selected = [].concat(value).includes(option.value);
            select.dispatchEvent(new Event('change', {bubbles:true}));
          }
          update();
        };
        button.addEventListener('click', () => {
          if (popup) { close(); return; }
          document.dispatchEvent(new Event('pf:close-filters'));
          popup = document.createElement('div'); popup.className = 'pf-filter-menu';
          popup.setAttribute('role','dialog'); popup.setAttribute('aria-label', label?.textContent.trim() || 'Filtre');
          const search = document.createElement('input'); search.type='search'; search.placeholder='Rechercher…';
          search.className='pf-filter-search'; search.setAttribute('aria-label','Rechercher une option');
          popup.append(search);
          const list = document.createElement('div'); list.className='pf-filter-options';
          const render = () => {
            list.replaceChildren();
            const normalize = text => String(text).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
            const filtered = options().filter(o => normalize(o.text).includes(normalize(search.value)));
            filtered.forEach(o => {
              const row = document.createElement('label'); row.className='pf-filter-option';
              const input = document.createElement('input'); input.type='checkbox';
              input.name=`pf-${select.id}`; input.checked=values().includes(o.value); input.disabled=!!o.disabled;
              const text = document.createElement('span'); text.textContent=o.text;
              row.append(input,text); list.append(row);
              input.addEventListener('change', () => {
                set(input.checked ? [...values(),o.value] : values().filter(v=>v!==o.value));
              });
            });
            if (!filtered.length) list.textContent='Aucun résultat';
          };
          {
            const actions = document.createElement('div'); actions.className='pf-filter-actions';
            // These resource filters interpret an empty selection as no restriction.
            for (const [caption,value] of [['Tous',options().filter(o=>!o.disabled).map(o=>o.value)],['Aucun',[]]]) {
              const action=document.createElement('button');action.type='button';action.textContent=caption;
              action.addEventListener('click',()=>{set(value);render();});actions.append(action);
            }
            popup.append(actions);
          }
          popup.append(list);document.body.append(popup);render();reposition();
          button.setAttribute('aria-expanded','true'); search.focus();
          search.addEventListener('input',render);
          popup.addEventListener('keydown',event=>{
            if(event.key==='Escape'){event.preventDefault();close(true);}
            if(event.key==='Tab') {
              const items=Array.from(popup.querySelectorAll('input:not(:disabled),button'));
              if(event.shiftKey && event.target===items[0]){event.preventDefault();items.at(-1).focus();}
              else if(!event.shiftKey && event.target===items.at(-1)){event.preventDefault();items[0].focus();}
            }
          });
          document.addEventListener('pointerdown',outside);
          window.addEventListener('resize',reposition);document.addEventListener('scroll',reposition,true);
        });
        document.addEventListener('pf:close-filters',()=>close());
      });
      root.addEventListener('click',()=>setTimeout(()=>updates.forEach(update=>update()),0));
      const reset=root.querySelector('.resetbox');
      if(reset) reset.innerHTML='<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Réinitialiser';
    });
  };
  if (document.readyState === 'complete') start(); else window.addEventListener('load',start,{once:true});
})();
