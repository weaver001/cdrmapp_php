const page = document.querySelector('#report-map-page');
const message = document.querySelector('#report-map-status');

async function loadReportMap() {
    if (!page.dataset.mapKey) throw new Error('Google Maps is not configured. Contact your administrator.');
    // Use the existing authorized detail endpoint, never an unscoped list or a search match.
    const response = await fetch(`/api/v1/incidents/${page.dataset.incidentId}`, {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('This incident could not be loaded. Return to the dashboard and try again.');
    const { data: incident } = await response.json();
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

loadReportMap().catch(error => { message.hidden = false; message.textContent = error.message; });
