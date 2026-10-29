const csrf = document.querySelector('meta[name=csrf-token]')?.content;
const staffMenu = document.querySelector('[data-staff-menu]');
if (staffMenu && window.matchMedia('(max-width: 767px)').matches) staffMenu.open = false;
export async function api(path, method = 'GET', body) {
    const response = await fetch(path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, ...(body ? { body: JSON.stringify(body) } : {}) });
    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(Object.values(error.error?.fields || {}).flat().join(' ') || error.error?.message || `Request failed (${response.status})`);
    }
    return response.json();
}
async function upload(path, file) {
    const body = new FormData(); body.append('image', file);
    let response;
    try {
        response = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body, signal: AbortSignal.timeout(60000) });
    } catch (error) {
        throw new Error(error.name === 'TimeoutError' ? 'Image upload timed out. Check the saved image before retrying.' : 'Image upload could not connect. Check your connection and retry.');
    }
    const result = await response.json().catch(() => null);
    if (!response.ok) throw new Error(Object.values(result?.error?.fields || {}).flat().join(' ') || result?.error?.message || `Image upload failed (HTTP ${response.status}).`);
    if (!result) throw new Error('Image upload returned an unexpected response. Refresh the page and sign in again if needed.');
    return result;
}
for (const image of document.querySelectorAll('[data-profile-avatar] [data-avatar-image]')) {
    image.addEventListener('load', () => { image.hidden = false; });
    image.addEventListener('error', () => { image.hidden = true; });
    if (image.getAttribute('src') && image.complete) image.hidden = image.naturalWidth === 0;
}
const photoForm = document.querySelector('#photo-form');
let uploadingPhoto = false;
photoForm?.addEventListener('submit', async event => {
    event.preventDefault();
    if (uploadingPhoto) return;
    const message = document.querySelector('#form-status');
    const file = photoForm.elements.image.files[0];
    if (!file) { message.textContent = 'Select a profile photo first.'; return; }
    const submit = photoForm.querySelector('button');
    uploadingPhoto = true; submit.disabled = true; message.textContent = 'Uploading profile photo…';
    try {
        const result = await upload('/api/v1/me/photo', file);
        if (!result.data?.photo_url) throw new Error('Photo upload could not be confirmed. Refresh the page before retrying.');
        for (const image of document.querySelectorAll('[data-profile-avatar] [data-avatar-image]')) image.src = result.data.photo_url;
        photoForm.reset(); message.textContent = result.data.message || 'Profile image updated.';
    } catch (error) { message.textContent = error.message; }
    finally { uploadingPhoto = false; submit.disabled = false; }
});
const node = (tag, text, className) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (className) n.className = className; return n; };
const button = (text, action) => { const b = node('button', text, 'btn-secondary'); b.type = 'button'; b.addEventListener('click', action); return b; };
const date = value => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));
const statusLabel = value => value.replaceAll('_', ' ');
const reportStatusLabel = value => value === 'done' ? 'Done' : 'Need action';
const formBody = form => {
    const data = Object.fromEntries(new FormData(form));
    for (const input of form.querySelectorAll('input[type=checkbox]')) data[input.name] = input.checked;
    for (const [k, v] of Object.entries(data)) if (v === '') data[k] = null;
    const details={};for(const name of Object.keys(data)){if(name.startsWith('details.')){details[name.slice(8)]=data[name];delete data[name];}}if(Object.keys(details).length)data.details=details;
    delete data.id; delete data.image;
    return data;
};
const pageEditor = document.querySelector('#page-editor');
pageEditor?.addEventListener('submit', async event => {
    event.preventDefault();
    const status = document.querySelector('#page-status');
    const submit = pageEditor.querySelector('button[type=submit], button:not([type])');
    if (submit.disabled) return;
    submit.disabled = true;
    status.textContent = 'Saving page…';
    try {
        const result = await api(pageEditor.dataset.endpoint, 'PATCH', formBody(pageEditor));
        for (const name of ['eyebrow', 'heading', 'introduction', 'form_heading', 'form_introduction']) {
            const field = pageEditor.elements.namedItem(name);
            if (field) field.value = result.data[name] ?? '';
        }
        status.textContent = result.message || 'Page updated successfully.';
    } catch (error) { status.textContent = error.message; }
    finally { submit.disabled = false; }
});
for (const form of document.querySelectorAll('.api-form')) form.addEventListener('submit', async event => {
    event.preventDefault(); const status = document.querySelector('#form-status');
    try {
        const result = await api(form.dataset.endpoint, form.dataset.method, formBody(form));
        status.textContent = result.data.message || 'Profile updated.';
        if (result.data.redirect) { form.reset(); window.location.assign(result.data.redirect); }
    } catch (e) { status.textContent = e.message; }
});
const dashboard = document.querySelector('#dashboard');
if (dashboard) {
    const filters = document.querySelector('#filters'); const message = document.querySelector('#dashboard-status');
    const mapView = !!document.querySelector('#map');
    const initialFilters = new URLSearchParams(window.location.search);
    const focusId = initialFilters.get('focus');
    let focused = false;
    for (const field of filters.elements) if (field.name && initialFilters.has(field.name)) field.value = initialFilters.get(field.name);
    let page = 1, lastPage = 1, busy = false, map, overlay, HeatmapLayerClass, markers = [], latest = [], mapsPromise;
    const params = () => new URLSearchParams([...new FormData(filters)].filter(([, value]) => value));
    async function ensureMap() {
        if (!mapView) return false;
        if (!dashboard.dataset.mapKey) { document.querySelector('#map-status').textContent = 'Google Maps is not configured. Set GOOGLE_MAPS_WEB_KEY with a restricted web API key.'; return; }
        if (!mapsPromise) mapsPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            window.cdrmMapReady = resolve;
            const url = new URL('https://maps.googleapis.com/maps/api/js'); url.searchParams.set('key', dashboard.dataset.mapKey); url.searchParams.set('callback', 'cdrmMapReady'); url.searchParams.set('loading', 'async');
            script.src = url; script.onerror = () => reject(new Error('Google Maps could not load. Check network and API key configuration.')); document.head.append(script);
        });
        await mapsPromise;
        if (!map) {
            const { GoogleMapsOverlay, HeatmapLayer } = await import('./map-overlay'); HeatmapLayerClass = HeatmapLayer;
            map = new google.maps.Map(document.querySelector('#map'), { center: { lat: 14.8139, lng: 121.0453 }, zoom: 13, mapTypeControl: false });
            overlay = new GoogleMapsOverlay({ interleaved: false, layers: [] }); overlay.setMap(map);
        }
        return map;
    }
    async function updateMap() {
        if (!await ensureMap()) return;
        const selected = latest.find(i => String(i.id) === focusId);
        if (selected && !focused) {
            map.panTo({ lat: selected.latitude, lng: selected.longitude });
            map.setZoom(16);
            focused = true;
        }
        markers.forEach(m => m.setMap(null)); markers = [];
        if (document.querySelector('#show-pins').checked) for (const i of latest) {
            const icon = { FIRE: 'fire', PNP: 'police', MDRRMO: 'mdrrmo' }[i.agency?.type];
            if (!icon) continue;
            const pin = i.workflow_status === 'done' ? `${icon}-done` : icon;
            const m = new google.maps.Marker({ position: { lat: i.latitude, lng: i.longitude }, map, title: `${i.is_test ? '[TEST] ' : ''}${i.agency?.name || 'Needs triage'} · ${reportStatusLabel(i.workflow_status)} · ${i.public_reference}`, icon: { url: `/images/map/${pin}.svg`, scaledSize: new google.maps.Size(40, 48), anchor: new google.maps.Point(20, 47) } });
            m.addListener('click', () => detail(i.id)); markers.push(m);
        }
        const p = params(); p.set('exploratory', document.querySelector('#exploratory').checked ? '1' : '0');
        const result = await api(`/api/v1/analytics/heatmap?${p}`);
        overlay.setProps({ layers: [new HeatmapLayerClass({ id: 'validated-incident-density', data: result.data.points, getPosition: p => [p.longitude, p.latitude], getWeight: p => p.weight, radiusPixels: 45, intensity: 1 })] });
        document.querySelector('#map-status').textContent = `${selected ? `Viewing report ${selected.public_reference}. ` : ''}${result.data.points.length} eligible density cells. ${result.data.exploratory ? 'Exploratory view includes unverified and uncertain locations.' : 'Validated incidents only.'}`;
    }
    const completionDialog = document.querySelector('#complete-report');
    const completionForm = document.querySelector('#complete-report-form');
    const completionNote = document.querySelector('#completion-note');
    let completionId = null, completing = false;
    async function changeStatus(id, operation, note) {
        await api(`/api/v1/incidents/${id}/status`, 'PATCH', { status: operation, ...(note === undefined ? {} : { note }) });
        await load();
        if (document.querySelector('#incident-detail')?.open) await detail(id);
    }
    async function action(id, operation) {
        if (operation === 'done') {
            completionId = id; completionForm.reset(); completionNote.setCustomValidity('');
            document.querySelector('#completion-status').textContent = '';
            document.querySelector('#complete-reference').textContent = latest.find(i => i.id === id)?.public_reference || `Report ${id}`;
            completionDialog.showModal(); completionNote.focus();
            return;
        }
        try { await changeStatus(id, operation); } catch (e) { message.textContent = e.message; }
    }
    completionForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (completing) return;
        const note = completionNote.value.trim();
        completionNote.setCustomValidity(note ? '' : 'Enter response notes before marking the report as done.');
        if (!completionForm.reportValidity()) return;
        completing = true;
        for (const id of ['complete-submit', 'complete-cancel']) document.querySelector(`#${id}`).disabled = true;
        document.querySelector('#completion-status').textContent = 'Saving response notes…';
        try {
            await changeStatus(completionId, 'done', note);
            completionDialog.close(); completionForm.reset();
        } catch (error) { document.querySelector('#completion-status').textContent = error.message; }
        finally {
            completing = false;
            for (const id of ['complete-submit', 'complete-cancel']) document.querySelector(`#${id}`).disabled = false;
        }
    });
    completionNote?.addEventListener('input', () => completionNote.setCustomValidity(''));
    document.querySelector('#complete-cancel')?.addEventListener('click', () => completionDialog.close());
    completionDialog?.addEventListener('cancel', event => { if (completing) event.preventDefault(); });
    async function detail(id) {
        try {
            const { data: i } = await api(`/api/v1/incidents/${id}`); const body = document.querySelector('#detail-body'); body.replaceChildren(node('h2', i.public_reference, 'text-xl font-bold'), node('p', i.description, 'my-4 whitespace-pre-line'), node('p', `${i.latitude}, ${i.longitude} · ${i.location_source} · accuracy: ${i.accuracy_m ?? 'not measured'} m`), node('p', `Reporter: ${i.reporter?.name || 'restricted'} · ${i.address || 'No address supplied'}`), node('h3', 'Status history', 'font-bold mt-5'));
            body.append(node('p', `Status: ${reportStatusLabel(i.workflow_status)}`, 'font-semibold my-3'), button(i.workflow_status === 'done' ? 'Mark as undone' : 'Mark as done', () => action(i.id, i.workflow_status === 'done' ? 'need_action' : 'done')));
            for (const h of i.histories || []) body.append(node('p', `${date(h.created_at)} · ${statusLabel(h.new_status)} · ${h.note || ''}`, 'mt-2'));
            for (const attachment of i.attachments || []) { const a=node('a',`Download incident image ${attachment.id}`,'block underline my-2');a.href=`/api/v1/incidents/${i.id}/attachments/${attachment.id}`;body.append(a); }
            body.append(node('h3', 'Response log', 'font-bold mt-5'));
            if (!i.responses?.length) body.append(node('p', 'No response notes recorded.', 'mt-2 text-sm'));
            for (const response of i.responses || []) {
                const entry = node('section', null, 'mt-3 border-t pt-3');
                entry.append(node('p', `${date(response.occurred_at)} · ${response.officer?.name || `Staff #${response.officer_id}`} · ${response.outcome || 'Response'}`, 'text-sm font-semibold'), node('p', response.note, 'mt-2 whitespace-pre-wrap'));
                body.append(entry);
            }
            const notices=await api('/api/v1/notifications');await Promise.all(notices.data.filter(n=>n.data.incident_id===i.id).map(n=>api(`/api/v1/notifications/${n.id}/read`,'POST',{})));
            const dialog = document.querySelector('#incident-detail'); if (!dialog.open) dialog.showModal();
        } catch (e) { message.textContent = e.message; }
    }
    async function load() {
        if (busy) return; busy = true;
        try {
            const p = params(); p.set('page', page);
            const [summary, incidents, notifications] = await Promise.all([api(`/api/v1/dashboard/summary?${p}`), api(`/api/v1/incidents?${p}`), api('/api/v1/notifications')]);
            const cards = document.querySelector('#summary'); cards.replaceChildren();
            const metrics = [
                ['Reports', summary.data.total, 'orange', 'All authorized reports', 'M9 5H5v16h14V5h-4M9 3h6v4H9zM8 12h8M8 16h6'],
                ['Need action', summary.data.by_status.need_action || 0, 'burgundy', 'Reports awaiting action', 'M9 6h12M9 12h12M9 18h12M3 6h.01M3 12h.01M3 18h.01'],
                ['Done', summary.data.by_status.done || 0, 'green', 'Completed reports', 'M22 11v1a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3'],
            ];
            for (const [label, value, tone, caption, path] of metrics) {
                const card = node('div', null, `stat-card stat-${tone}`), top = node('div', null, 'stat-card-body');
                const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                for (const [key, value] of Object.entries({ viewBox: '0 0 24 24', width: '42', height: '42', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) icon.setAttribute(key, value);
                const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path'); shape.setAttribute('d', path); icon.append(shape);
                const number = node('div', null, 'text-right'); number.append(node('p', String(value), 'stat-card-value'), node('p', label, 'stat-card-label'));
                top.append(icon, number); card.append(top, node('p', caption, 'stat-caption')); cards.append(card);
            }
            document.querySelector('#unread').textContent = `${notifications.meta?.total ?? notifications.total ?? notifications.data.length} unread notifications`;
            const rows = document.querySelector('#incidents'); rows?.replaceChildren(); latest = incidents.data;
            if (rows && !latest.length) { const tr = node('tr'); const td = node('td', 'No reports match these filters.'); td.colSpan = 7; tr.append(td); rows.append(tr); }
            if (rows) for (const i of latest) {
                const tr = node('tr'); const ref = node('td'); ref.append(button(i.public_reference.slice(0, 8), () => detail(i.id)), node('p', date(i.received_at), 'text-xs mt-2'));
                const actions = node('td'); actions.append(button(i.workflow_status === 'done' ? 'Mark as undone' : 'Mark as done', () => action(i.id, i.workflow_status === 'done' ? 'need_action' : 'done')));
                const location = node('td'); if (i.address) location.append(node('p', i.address));
                location.append(node('p', `Latitude: ${Number(i.latitude).toFixed(6)} · Longitude: ${Number(i.longitude).toFixed(6)}`, 'text-sm'));
                const status = node('td'); status.append(node('span', reportStatusLabel(i.workflow_status), `status-badge ${i.workflow_status === 'done' ? 'status-done' : 'status-need_action'}`));
                const mapCell = node('td'), mapLink = node('a', null, 'btn-secondary inline-flex items-center justify-center');
                mapLink.href = `/incident-map?${new URLSearchParams({ q: i.public_reference, focus: String(i.id) })}`;
                mapLink.title = 'View on map'; mapLink.setAttribute('aria-label', `View report ${i.public_reference} on map`);
                const eye = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                for (const [name, value] of Object.entries({ viewBox: '0 0 24 24', width: '20', height: '20', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })) eye.setAttribute(name, value);
                const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path'); shape.setAttribute('d', 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'); eye.append(shape); mapLink.append(eye); mapCell.append(mapLink);
                tr.append(ref, node('td', i.description), node('td', i.agency?.name || 'Needs triage'), location, status, mapCell, actions); rows.append(tr);
            }
            lastPage = incidents.meta.last_page; document.querySelector('#page').textContent = `Page ${page} of ${lastPage}`; document.querySelector('#previous').disabled = page <= 1; document.querySelector('#next').disabled = page >= lastPage;
            const exportLink = document.querySelector('#export');
            if (exportLink) exportLink.href = `/api/v1/reports/export?${params()}`;
            const switchView = document.querySelector('#switch-view');
            if (switchView) switchView.href = `${new URL(switchView.href).pathname}?${params()}`;
            message.textContent = `Updated ${date(new Date())}. Refreshes every 15 seconds while visible.`;
            await updateMap();
        } catch (e) { message.textContent = `Could not refresh: ${e.message}. Existing information may be stale.`; } finally { busy = false; }
    }
    filters.addEventListener('submit', e => { e.preventDefault(); page = 1; load(); });
    document.querySelector('#refresh').onclick = load;
    const printButton = document.querySelector('#print');
    if (printButton) printButton.onclick = () => window.print();
    document.querySelector('#previous').onclick = () => { if (page > 1) { page--; load(); } }; document.querySelector('#next').onclick = () => { if (page < lastPage) { page++; load(); } };
    for (const id of ['exploratory', 'show-pins']) document.querySelector(`#${id}`)?.addEventListener('change', () => updateMap().catch(e => { document.querySelector('#map-status').textContent = e.message; }));
    setInterval(() => { if (!document.hidden) load(); }, 15000); document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); }); load();
}
const management = document.querySelector('#management');
if (management) {
    const module = management.dataset.module; const editor = document.querySelector('#editor'); const message = document.querySelector('#form-status');
    const content = !['users', 'messages', 'agencies', 'audit'].includes(module); let page = 1, last = 1;
    const endpoint = content ? `/api/v1/management/content/${module}` : module === 'agencies' ? '/api/v1/agencies' : `/api/v1/management/${module}`;
    const auditActions = {
        'user.created': 'Added account', 'user.updated': 'Updated account', 'user.saved': 'Saved account',
        'content.created': 'Added content', 'content.updated': 'Updated content', 'content.saved': 'Saved content',
        'content.deleted': 'Removed content', 'content.image_updated': 'Updated content image',
        'page.updated': 'Updated public page',
        'agency.updated': 'Updated agency', 'dispatch.created': 'Added agency routing', 'dispatch.updated': 'Updated agency routing', 'dispatch.saved': 'Saved dispatch rule',
        'message.trash': 'Removed contact message', 'message.restore': 'Restored contact message', 'message.destroy': 'Permanently deleted contact message',
        'incident.created': 'Added incident report', 'incident.test_created': 'Added test incident report', 'incident.status': 'Changed incident status', 'incident.verified': 'Verified incident report', 'incident.assignment': 'Changed incident assignment',
    };
    const auditValue = value => value == null ? 'None' : typeof value === 'boolean' ? (value ? 'Yes' : 'No') : typeof value === 'object' ? Object.entries(value).map(([key, item]) => `${statusLabel(key)}: ${auditValue(item)}`).join('; ') : String(value);
    async function mutate(path, method, data, successPopup = false) {
        try {
            const result = await api(path, method, data);
            message.textContent = result.data?.message || 'Saved.';
            if (successPopup) window.alert('Success');
            await load();
        } catch (e) { message.textContent = e.message; }
    }
    async function load() {
        try {
            const result = await api(`${endpoint}?page=${page}&trash=${document.querySelector('#trash')?.checked ? 1 : 0}`); const list = document.querySelector('#records'); list.replaceChildren();
            for (const item of result.data) {
                const card = node('article', null, 'panel'); card.append(node('h2', module === 'audit' ? (auditActions[item.action] || statusLabel(item.action)) : item.title || item.name || item.action, 'font-semibold text-lg'));
                if (module === 'audit') {
                    card.append(node('p', item.record_label || `${item.record_type} #${item.record_id}`, 'font-semibold mt-2'));
                    card.append(node('p', `By ${item.actor_name || item.actor?.name || 'Unknown user'} · ${date(item.created_at)}`, 'text-sm my-2'));
                    const metadata = item.metadata || {};
                    const details = node('dl', null, 'grid gap-2 whitespace-pre-line text-sm');
                    if (metadata.before || metadata.after) {
                        const before = metadata.before || {}, after = metadata.after || {};
                        for (const key of new Set([...Object.keys(before), ...Object.keys(after)])) {
                            if (JSON.stringify(before[key]) === JSON.stringify(after[key])) continue;
                            details.append(node('dt', statusLabel(key), 'font-semibold'), node('dd', `${auditValue(before[key])} → ${auditValue(after[key])}`));
                        }
                    } else {
                        for (const [key, value] of Object.entries(metadata)) details.append(node('dt', statusLabel(key), 'font-semibold'), node('dd', auditValue(value)));
                    }
                    card.append(details);
                }
                else if (module === 'agencies') {
                    card.append(node('p', `${item.type === 'FIRE' ? 'BFP' : item.type} · ${item.jurisdiction || 'Jurisdiction not configured'} · ${item.emergency_phone || 'No emergency number configured'}`));
                    card.append(button('Edit contact / jurisdiction', async () => { const jurisdiction = prompt('Jurisdiction description (does not define automatic routing boundaries):', item.jurisdiction || ''); if (jurisdiction === null) return; const emergency_phone = prompt('Official confirmed emergency telephone:', item.emergency_phone || ''); if (emergency_phone === null) return; await mutate(`/api/v1/management/agencies/${item.id}`, 'PATCH', { name: item.name, jurisdiction, emergency_phone, active: item.active }); }));
                } else if (module === 'messages') {
                    card.append(node('p', item.email), node('p', item.message, 'whitespace-pre-line my-3'));
                    card.append(button(item.deleted_at ? 'Restore' : 'Move to trash', () => mutate(`${endpoint}/${item.id}/${item.deleted_at ? 'restore' : 'trash'}`, 'POST', {})));
                    if (item.deleted_at) card.append(button('Permanently delete', () => { if (confirm('Permanently delete this contact message?')) mutate(`${endpoint}/${item.id}/destroy`, 'POST', { confirm: true }); }));
                } else {
                    card.append(node('p', content ? item.body : `${item.email} · ${item.role} · ${item.active ? 'Active' : 'Inactive'}`, 'my-3 whitespace-pre-line'));
                    if (content) {
                        card.append(node('p', item.published ? 'Published' : 'Draft', 'text-sm mb-3'));
                        if (item.image_url) {
                            const image = node('img', null, 'rounded-lg mb-3 max-h-48'); image.src = item.image_url; image.alt = 'Saved image';
                            const imageStatus = node('p', 'Loading saved image…', 'text-sm mb-3');
                            image.addEventListener('load', () => { imageStatus.textContent = 'Saved image loaded.'; });
                            image.addEventListener('error', () => { imageStatus.textContent = 'The saved image could not load. Select the image again and save.'; });
                            card.append(image, imageStatus);
                        } else card.append(node('p', 'No image attached.', 'text-sm mb-3'));
                    }
                    card.append(button('Edit', () => { for (const field of editor.elements) { if (!field.name || field.type === 'file') continue; if (field.type === 'checkbox') field.checked = !!item[field.name]; else field.value = field.name.startsWith('details.') ? (item.details?.[field.name.slice(8)] ?? '') : (item[field.name] ?? ''); } editor.scrollIntoView({ behavior: 'smooth' }); }));
                    if (content) card.append(button('Remove', () => { if (confirm('Remove this item from publication?')) mutate(`${endpoint}/${item.id}`, 'DELETE'); }));
                    else if(module==='users') card.append(button('Send password reset', () => mutate(`${endpoint}/${item.id}/reset-password`, 'POST', {}, true)));
                }
                list.append(card);
            }
            if (!result.data.length) list.append(node('p', 'No records yet.', 'panel'));
            last = result.meta?.last_page || result.last_page || 1; document.querySelector('#manage-page').textContent = `Page ${page} of ${last}`; document.querySelector('#manage-prev').disabled = page <= 1; document.querySelector('#manage-next').disabled = page >= last;
        } catch (e) { message.textContent = e.message; }
    }
    let saving = false;
    editor?.addEventListener('invalid', () => { message.textContent = 'Complete the highlighted fields before saving.'; }, true);
    editor?.addEventListener('submit', async e => {
        e.preventDefault();
        if (saving) return;
        const id = editor.elements.id.value;
        const file = content ? editor.elements.namedItem('image')?.files?.[0] : null;
        const body = formBody(editor);
        const fields = [...editor.elements].map(field => [field, field.disabled]);
        let contentSaved = false;
        saving = true;
        fields.forEach(([field]) => { field.disabled = true; });
        message.textContent = content ? 'Saving content…' : 'Saving…';
        try {
            const result = await api(editor.dataset.endpoint + (id ? `/${id}` : ''), id ? 'PATCH' : 'POST', body);
            if (content) {
                editor.elements.id.value = result.data.id;
                contentSaved = true;
            }
            if (file) {
                message.textContent = 'Content saved. Uploading image…';
                const uploaded = await upload(`${editor.dataset.endpoint}/${result.data.id}/image`, file);
                if (!uploaded.data?.image_path || !uploaded.data?.image_url) throw new Error('The server did not confirm a saved image. Please retry the image upload.');
                editor.elements.namedItem('image').value = '';
            }
            editor.reset();
            editor.elements.id.value = '';
            message.textContent = result.message || (file ? 'Content and image saved successfully.' : content ? 'Content saved successfully.' : module === 'users' ? 'Account saved successfully.' : 'Saved successfully.');
            await load();
        } catch (error) {
            message.textContent = contentSaved ? `Content saved, but image upload failed: ${error.message}` : error.message;
        } finally {
            saving = false;
            fields.forEach(([field, disabled]) => { field.disabled = disabled; });
        }
    });
    editor?.addEventListener('reset', () => { editor.elements.id.value = ''; message.textContent = ''; });
    document.querySelector('#trash')?.addEventListener('change', () => { page = 1; load(); });
    document.querySelector('#manage-prev').onclick = () => { if (page > 1) { page--; load(); } }; document.querySelector('#manage-next').onclick = () => { if (page < last) { page++; load(); } }; load();
}

const publicMap=document.querySelector('[data-contact-map]');
if(publicMap?.dataset.key){window.cdrmPublicMapReady=()=>{const position={lat:Number(publicMap.dataset.latitude),lng:Number(publicMap.dataset.longitude)};const map=new google.maps.Map(publicMap,{center:position,zoom:14});new google.maps.Marker({map,position,title:'Official contact location'});};const script=document.createElement('script');const url=new URL('https://maps.googleapis.com/maps/api/js');url.searchParams.set('key',publicMap.dataset.key);url.searchParams.set('callback','cdrmPublicMapReady');url.searchParams.set('loading','async');script.src=url;script.onerror=()=>{publicMap.textContent='Map could not load. Please use the published contact address.';};document.head.append(script);}
