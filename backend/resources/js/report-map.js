const page = document.querySelector('#report-map-page');
const message = document.querySelector('#report-map-status');
const details = document.querySelector('#report-details-body');
const node = (tag, text, className = '') => {
    const element = document.createElement(tag);
    element.textContent = text;
    element.className = className;
    return element;
};
const date = value => value && !Number.isNaN(Date.parse(value))
    ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value))
    : 'Not recorded';

function renderDetails(incident) {
    details.replaceChildren();
    const field = (label, value) => {
        details.append(node('h2', label, 'font-semibold mt-4'), node('p', value, 'mt-1 whitespace-pre-wrap'));
    };
    field('Reference', incident.public_reference);
    const done = incident.workflow_status === 'done';
    details.append(node('p', done ? 'Done' : 'Need action', `status-badge mt-3 ${done ? 'status-done' : 'status-need_action'}`));
    field('Description', incident.description);
    field('Agency', incident.agency?.name || 'Unassigned');
    field('Category', incident.category?.name || 'Not recorded');
    field('Reporter', incident.reporter?.name || 'Not recorded');
    field('Received (Asia/Manila)', date(incident.received_at));
    field('Location', incident.address || 'No address supplied');
    field('Coordinates', `${incident.latitude}, ${incident.longitude}`);
    field('Location source', `${incident.location_source || 'Not recorded'} · Accuracy: ${incident.accuracy_m == null ? 'not measured' : `${incident.accuracy_m} m`}`);
    details.append(node('h2', 'Status history', 'font-semibold mt-5'));
    if (!incident.histories?.length) details.append(node('p', 'No status history recorded.', 'mt-2'));
    for (const history of incident.histories || []) {
        details.append(node('p', `${date(history.created_at)} · ${(history.new_status || '').replaceAll('_', ' ')}\n${history.note || ''}`, 'mt-3 whitespace-pre-wrap'));
    }
    details.append(node('h2', 'Response log', 'font-semibold mt-5'));
    if (!incident.responses?.length) details.append(node('p', 'No response notes recorded.', 'mt-2'));
    for (const response of incident.responses || []) {
        const entry = document.createElement('section');
        entry.className = 'mt-3 border-t pt-3';
        entry.append(node('p', `${date(response.occurred_at)} · ${response.officer?.name || `Staff #${response.officer_id}`} · ${response.outcome || 'Response'}`, 'text-sm font-semibold'), node('p', response.note, 'mt-2 whitespace-pre-wrap'));
        details.append(entry);
    }
    if (incident.attachments?.length) details.append(node('h2', 'Attachments', 'font-semibold mt-5'));
    for (const attachment of incident.attachments || []) {
        const link = node('a', `Download incident image ${attachment.id}`, 'block underline mt-2');
        link.href = `/api/v1/incidents/${incident.id}/attachments/${attachment.id}`;
        details.append(link);
    }
}

async function loadReportMap() {
    // Use the existing authorized detail endpoint, never an unscoped list or a search match.
    const response = await fetch(`/api/v1/incidents/${page.dataset.incidentId}`, {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('This incident could not be loaded. Return to the dashboard and try again.');
    const { data: incident } = await response.json();
    renderDetails(incident);
    if (!page.dataset.mapKey) throw new Error('Google Maps is not configured. Contact your administrator.');
    const position = { lat: Number(incident.latitude), lng: Number(incident.longitude) };
    if (incident.latitude == null || incident.longitude == null || !Number.isFinite(position.lat) || !Number.isFinite(position.lng)) {
        throw new Error('This incident has no valid map location.');
    }
    await new Promise((resolve, reject) => {
        window.cdrmReportMapReady = resolve;
        window.gm_authFailure = () => { message.hidden = false; message.textContent = 'Google Maps could not load. Contact your administrator.'; };
        const script = document.createElement('script');
        const url = new URL('https://maps.googleapis.com/maps/api/js');
        url.searchParams.set('key', page.dataset.mapKey);
        url.searchParams.set('callback', 'cdrmReportMapReady');
        url.searchParams.set('loading', 'async');
        script.src = url;
        script.onerror = () => reject(new Error('Google Maps could not load. Check your connection and try again.'));
        document.head.append(script);
    });
    const map = new google.maps.Map(document.querySelector('#report-map'), {
        center: position, zoom: 16, disableDefaultUI: true,
    });
    const agencyIcon = { FIRE: 'fire', PNP: 'police', MDRRMO: 'mdrrmo' }[incident.agency?.type];
    const pin = agencyIcon && (incident.workflow_status === 'done' ? `${agencyIcon}-done` : agencyIcon);
    new google.maps.Marker({
        position, map, title: incident.public_reference,
        ...(pin ? { icon: { url: `/images/map/${pin}.svg`, scaledSize: new google.maps.Size(40, 48), anchor: new google.maps.Point(20, 47) } } : {}),
    });
    message.hidden = true;
}

loadReportMap().catch(error => {
    if (!details.querySelector('h2')) details.textContent = 'Report details could not be loaded. Return to the dashboard and try again.';
    message.hidden = false; message.textContent = error.message;
});
