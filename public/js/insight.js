'use strict';
(() => {
const boundSearches = new WeakSet();
const initializeInsight = () => {
    document.querySelectorAll('[data-load-market-map]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => {
            const container = button.closest('[data-map-src]');
            const frame = document.createElement('iframe');
            frame.src = container.dataset.mapSrc;
            frame.title = container.dataset.mapTitle;
            frame.loading = 'lazy';
            frame.referrerPolicy = 'no-referrer';
            frame.className = 'market-location-map';
            container.replaceChildren(frame);
        });
    });
    if (window.flatpickr) {
        const modal = document.querySelector('#insight-settings .modal-body');
        const options = {locale: window.flatpickr.l10ns.fr || 'default', disableMobile: true, appendTo: modal || document.body, onReady: (_, __, instance) => instance.calendarContainer.classList.add('market-datepicker')};
        const dateInput = document.getElementById('digest-start');
        const timeInput = document.getElementById('digest-time');
        if (dateInput && !dateInput._flatpickr) window.flatpickr(dateInput, {...options, dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y', ariaDateFormat: 'j F Y'});
        if (timeInput && !timeInput._flatpickr) window.flatpickr(timeInput, {...options, enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5});
    }
    const recipientSearch = document.getElementById('insight-recipient-search');
    if (recipientSearch && !boundSearches.has(recipientSearch)) {
        boundSearches.add(recipientSearch);
        const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
        const recipients = Array.from(document.querySelectorAll('#insight-recipients [data-recipient]'), element => ({element, text: normalize(element.textContent)}));
        const empty = document.getElementById('insight-recipient-empty');
        const filterRecipients = () => {
            const terms = normalize(recipientSearch.value).trim().split(/\s+/).filter(Boolean);
            let visible = 0;
            recipients.forEach(({element, text}) => {
                element.hidden = !terms.every(term => text.includes(term));
                if (!element.hidden) visible++;
            });
            empty.hidden = recipients.length === 0 || visible > 0;
        };
        recipientSearch.addEventListener('input', filterRecipients);
        recipientSearch.addEventListener('keydown', event => {
            if (event.key === 'Enter') event.preventDefault();
        });
        filterRecipients();
    }
    const table = document.getElementById('insight-markets');
    const fields = document.getElementById('insight-fields');
    if (!window.DataTable) {
        if (table) document.getElementById('insight-table-error').hidden = false;
        return;
    }
    const language = {search: 'Rechercher :', searchPlaceholder: 'Intitulé, acheteur ou identifiant', lengthMenu: 'Afficher _MENU_ lignes', info: '_START_–_END_ sur _TOTAL_', infoEmpty: 'Aucune ligne', infoFiltered: '(sur _MAX_ au total)', zeroRecords: 'Aucun résultat', emptyTable: 'Aucun marché importé pour le moment.', processing: 'Chargement…', paginate: {first: 'Début', previous: 'Précédent', next: 'Suivant', last: 'Fin'}};
    const date = (value, type) => {
        if (type !== 'display' || !value) return value || 'Non renseignée';
        const parsed = new Date(value.replace(' ', 'T') + 'Z');
        return Number.isNaN(parsed.getTime()) ? 'Date invalide' : new Intl.DateTimeFormat('fr-FR', {dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Paris'}).format(parsed);
    };
    if (table && !DataTable.isDataTable(table)) {
        DataTable.ext.errMode = 'none';
        window.jQuery(table).on('dt-error.dt', () => { document.getElementById('insight-table-error').hidden = false; });
        new DataTable(table, {serverSide: true, processing: true, searchDelay: 400, pageLength: 25, language, order: [[2, 'asc']], ajax: table.dataset.url, columns: [
            {data: 'title', render: DataTable.render.text()}, {data: 'buyer', render: DataTable.render.text()},
            {data: 'deadline', render: date},
            {data: 'amount', orderable: false, searchable: false, render: (value, type) => {
                if (!value || value.singleValue == null) return 'Non renseigné';
                const number = Number(value.singleValue);
                if (!Number.isFinite(number)) return 'Non renseigné';
                return DataTable.render.text().display(new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 2}).format(number) + ' ' + (value.currencyCode || ''));
            }},
            {data: 'location', orderable: false, searchable: false, render: DataTable.render.text()},
            {data: 'statuses', orderable: false, searchable: false, render: DataTable.render.text()},
            {data: 'active', render: value => Number(value) === 1 ? 'Actif' : 'Hors sélection'},
            {data: 'updated_at', render: date}, {data: null, orderable: false, searchable: false, render: (value, type, row) => {
                if (type !== 'display') return '';
                const link = document.createElement('a'); link.href = row.url; link.textContent = 'Consulter →'; link.className = 'market-detail-link'; return link;
            }}
        ]});
    }
    if (fields && !DataTable.isDataTable(fields)) new DataTable(fields, {language: {...language, searchPlaceholder: 'Champ ou valeur'}, pageLength: 50, lengthMenu: [10, 25, 50, 100, 250, 500, 1000], order: [[0, 'asc']]});
};
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeInsight, {once: true});
} else {
    initializeInsight();
}
document.addEventListener('turbo:load', initializeInsight);
})();
