const csrf = document.querySelector('meta[name=csrf-token]')?.content;
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
 const response = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body });
 if (!response.ok) { const result = await response.json(); throw new Error(Object.values(result.error?.fields || {}).flat().join(' ') || result.error?.message || 'Upload failed.'); }
 return response.json();
}
document.querySelector('#photo-form')?.addEventListener('submit', async e => { e.preventDefault();const message = document.querySelector('#form-status');try {await upload('/api/v1/me/photo',e.target.elements.image.files[0]);message.textContent='Profile image updated.';}catch(error){message.textContent=error.message;} });
const node = (tag, text, className) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (className) n.className = className; return n; };
const button = (text, action) => { const b = node('button', text, 'btn-secondary'); b.type = 'button'; b.addEventListener('click', action); return b; };
const date = value => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value));
const statusLabel = value => value.replaceAll('_', ' ');
const formBody = form => {
    const data = Object.fromEntries(new FormData(form));
    for (const input of form.querySelectorAll('input[type=checkbox]')) data[input.name] = input.checked;
    for (const [k, v] of Object.entries(data)) if (v === '') data[k] = null;
    const details={};for(const name of Object.keys(data)){if(name.startsWith('details.')){details[name.slice(8)]=data[name];delete data[name];}}if(Object.keys(details).length)data.details=details;
    delete data.id; delete data.image;
    return data;
};
for (const form of document.querySelectorAll('.api-form')) form.addEventListener('submit', async event => {
    event.preventDefault(); const status = document.querySelector('#form-status');
    try { const result = await api(form.dataset.endpoint, form.dataset.method, formBody(form)); status.textContent = result.data.message || 'Profile updated.'; } catch (e) { status.textContent = e.message; }
});
const dashboard = document.querySelector('#dashboard');
if (dashboard) {
    const filters = document.querySelector('#filters'); const message = document.querySelector('#dashboard-status');
    let page = 1, lastPage = 1, busy = false, map, overlay, HeatmapLayerClass, markers = [], latest = [], mapsPromise;
    const params = () => new URLSearchParams([...new FormData(filters)].filter(([, value]) => value));
    async function ensureMap() {
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
        markers.forEach(m => m.setMap(null)); markers = [];
        if (document.querySelector('#show-pins').checked) for (const i of latest) {
            const color = i.agency?.type === 'MDRRMO' ? '#7e22ce' : i.agency?.type === 'FIRE' ? '#dc2626' : '#1d4ed8';
            const m = new google.maps.Marker({ position: { lat: i.latitude, lng: i.longitude }, map, title: `${i.agency?.name || 'Needs triage'} ${i.public_reference}`, icon: { path: google.maps.SymbolPath.CIRCLE, scale: 8, fillColor: color, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 } });
            m.addListener('click', () => detail(i.id)); markers.push(m);
        }
        const p = params(); p.set('exploratory', document.querySelector('#exploratory').checked ? '1' : '0');
        const result = await api(`/api/v1/analytics/heatmap?${p}`);
        overlay.setProps({ layers: [new HeatmapLayerClass({ id: 'validated-incident-density', data: result.data.points, getPosition: p => [p.longitude, p.latitude], getWeight: p => p.weight, radiusPixels: 45, intensity: 1 })] });
        document.querySelector('#map-status').textContent = `${result.data.points.length} eligible density cells. ${result.data.exploratory ? 'Exploratory view includes unverified and uncertain locations.' : 'Validated incidents only.'}`;
    }
    async function action(id, operation) {
        const note = prompt('Enter an operational note (required):'); if (!note) return;
        try {
            const verify = operation === 'verified';
            await api(`/api/v1/incidents/${id}/${verify ? 'verify' : 'status'}`, verify ? 'POST' : 'PATCH', verify ? { rationale: note } : { status: operation, note });
            await load();
        } catch (e) { message.textContent = e.message; }
    }
    async function detail(id) {
        try {
            const { data: i } = await api(`/api/v1/incidents/${id}`); const body = document.querySelector('#detail-body'); body.replaceChildren(node('h2', i.public_reference, 'text-xl font-bold'), node('p', i.description, 'my-4 whitespace-pre-line'), node('p', `${i.latitude}, ${i.longitude} · ${i.location_source} · accuracy: ${i.accuracy_m ?? 'not measured'} m`), node('p', `Reporter: ${i.reporter?.name || 'restricted'} · ${i.address || 'No address supplied'}`), node('h3', 'Status history', 'font-bold mt-5'));
            for (const h of i.histories || []) body.append(node('p', `${date(h.created_at)} · ${statusLabel(h.new_status)} · ${h.note || ''}`, 'mt-2'));
            for (const attachment of i.attachments || []) { const a=node('a',`Download incident image ${attachment.id}`,'block underline my-2');a.href=`/api/v1/incidents/${i.id}/attachments/${attachment.id}`;body.append(a); }
            body.append(node('h3', 'Response notes', 'font-bold mt-5'));
            for (const h of i.responses || []) body.append(node('p', `${date(h.occurred_at)} · ${h.note} · ${h.outcome || ''}`, 'mt-2'));
            body.append(button('Log response', async () => {
                const note = prompt('Response note:'); if (!note) return;
                try { await api(`/api/v1/incidents/${i.id}/responses`, 'POST', { note, occurred_at: new Date().toISOString() }); await detail(i.id); } catch (e) { message.textContent = e.message; }
            }));
            const assignment = node('form',null,'grid gap-3 mt-5'); const officerLabel=node('label','Assigned officer');const officer=node('select');officer.name='assigned_officer_id';officerLabel.append(officer);
            const agencyLabel=node('label','Routed agency');const agency=document.querySelector('#filters select[name=agency_id]').cloneNode(true);agency.options[0].textContent='Needs triage';agency.value=i.agency_id??'';agencyLabel.append(agency);
            async function listOfficers(){officer.replaceChildren(node('option','Unassigned'));officer.options[0].value='';const result=await api('/api/v1/officers'+(agency.value?'?agency_id='+agency.value:''));for(const user of result.data){const option=node('option',user.name);option.value=user.id;officer.append(option);}officer.value=i.assigned_officer_id??'';}
            if(dashboard.dataset.role==='admin'){assignment.append(agencyLabel);agency.onchange=listOfficers;}assignment.append(officerLabel);await listOfficers();
            const noteLabel=node('label','Assignment / rerouting reason');const note=node('textarea');note.required=true;note.maxLength=2000;noteLabel.append(note);assignment.append(noteLabel);const save=node('button','Save assignment','btn');save.type='submit';assignment.append(save);assignment.onsubmit=async event=>{event.preventDefault();try{await api(`/api/v1/incidents/${i.id}/assign`,'POST',{assigned_officer_id:officer.value?Number(officer.value):null,...(dashboard.dataset.role==='admin'?{agency_id:agency.value?Number(agency.value):null}:{}),note:note.value});await detail(i.id);await load();}catch(error){message.textContent=error.message;}};body.append(assignment);
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
            for (const [label, value] of [['Received reports', summary.data.total], ['Awaiting action', (summary.data.by_status.submitted || 0) + (summary.data.by_status.received || 0)], ['In progress', summary.data.by_status.in_progress || 0], ['Validated', summary.data.validated]]) { const card = node('div', null, 'panel'); card.append(node('p', label, 'text-sm text-slate-600'), node('p', String(value), 'text-3xl font-bold mt-2')); cards.append(card); }
            document.querySelector('#unread').textContent = `${notifications.meta?.total ?? notifications.total ?? notifications.data.length} unread notifications`;
            const rows = document.querySelector('#incidents'); rows.replaceChildren(); latest = incidents.data;
            if (!latest.length) { const tr = node('tr'); const td = node('td', 'No reports match these filters.'); td.colSpan = 5; tr.append(td); rows.append(tr); }
            const transitions = { submitted: ['received', 'rejected', 'duplicate'], received: ['verified', 'rejected', 'duplicate'], verified: ['in_progress'], in_progress: ['resolved'], resolved: ['closed'] };
            for (const i of latest) {
                const tr = node('tr'); const ref = node('td'); ref.append(button(i.public_reference.slice(0, 8), () => detail(i.id)), node('p', date(i.received_at), 'text-xs mt-2'));
                const actions = node('td'); for (const next of transitions[i.workflow_status] || []) actions.append(button(statusLabel(next), () => action(i.id, next)));
                tr.append(ref, node('td', i.description), node('td', i.agency?.name || 'Needs triage'), node('td', statusLabel(i.workflow_status)), actions); rows.append(tr);
            }
            lastPage = incidents.meta.last_page; document.querySelector('#page').textContent = `Page ${page} of ${lastPage}`; document.querySelector('#previous').disabled = page <= 1; document.querySelector('#next').disabled = page >= lastPage;
            document.querySelector('#export').href = `/api/v1/reports/export?${params()}`;
            message.textContent = `Updated ${date(new Date())}. Refreshes every 15 seconds while visible.`;
            await updateMap();
        } catch (e) { message.textContent = `Could not refresh: ${e.message}. Existing information may be stale.`; } finally { busy = false; }
    }
    filters.addEventListener('submit', e => { e.preventDefault(); page = 1; load(); });
    document.querySelector('#refresh').onclick = load; document.querySelector('#print').onclick = () => window.print();
    document.querySelector('#previous').onclick = () => { if (page > 1) { page--; load(); } }; document.querySelector('#next').onclick = () => { if (page < lastPage) { page++; load(); } };
    for (const id of ['exploratory', 'show-pins']) document.querySelector(`#${id}`).onchange = () => updateMap().catch(e => { document.querySelector('#map-status').textContent = e.message; });
    setInterval(() => { if (!document.hidden) load(); }, 15000); document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); }); load();
}
const management = document.querySelector('#management');
if (management) {
    const module = management.dataset.module; const editor = document.querySelector('#editor'); const message = document.querySelector('#form-status');
    const content = !['users', 'messages', 'agencies', 'audit','dispatch-rules'].includes(module); let page = 1, last = 1;
    const endpoint = content ? `/api/v1/management/content/${module}` : module === 'agencies' ? '/api/v1/agencies' : `/api/v1/management/${module}`;
    async function mutate(path, method, data) { try { await api(path, method, data); message.textContent = 'Saved.'; await load(); } catch (e) { message.textContent = e.message; } }
    async function load() {
        try {
            const result = await api(`${endpoint}?page=${page}&trash=${document.querySelector('#trash')?.checked ? 1 : 0}`); const list = document.querySelector('#records'); list.replaceChildren();
            for (const item of result.data) {
                const card = node('article', null, 'panel'); card.append(node('h2', item.title || item.name || item.action, 'font-semibold text-lg'));
                if (module === 'audit') card.append(node('p', `${date(item.created_at)} · actor ${item.actor_id} · ${JSON.stringify(item.metadata)}`));
                else if (module === 'agencies') {
                    card.append(node('p', `${item.type === 'FIRE' ? 'BFP' : item.type} · ${item.jurisdiction || 'Jurisdiction not configured'} · ${item.emergency_phone || 'No emergency number configured'}`));
                    card.append(button('Edit contact / jurisdiction', async () => { const jurisdiction = prompt('Jurisdiction description (does not define automatic routing boundaries):', item.jurisdiction || ''); if (jurisdiction === null) return; const emergency_phone = prompt('Official confirmed emergency telephone:', item.emergency_phone || ''); if (emergency_phone === null) return; await mutate(`/api/v1/management/agencies/${item.id}`, 'PATCH', { name: item.name, jurisdiction, emergency_phone, active: item.active }); }));
                } else if (module === 'messages') {
                    card.append(node('p', item.email), node('p', item.message, 'whitespace-pre-line my-3'));
                    card.append(button(item.deleted_at ? 'Restore' : 'Move to trash', () => mutate(`${endpoint}/${item.id}/${item.deleted_at ? 'restore' : 'trash'}`, 'POST', {})));
                    if (item.deleted_at) card.append(button('Permanently delete', () => { if (confirm('Permanently delete this contact message?')) mutate(`${endpoint}/${item.id}/destroy`, 'POST', { confirm: true }); }));
                } else {
                    card.append(node('p', content ? item.body : `${item.email} · ${item.role} · ${item.active ? 'Active' : 'Inactive'}`, 'my-3 whitespace-pre-line'));
                    if (content) card.append(node('p', item.published ? 'Published' : 'Draft', 'text-sm mb-3'));
                    card.append(button('Edit', () => { for (const field of editor.elements) { if (!field.name || field.type === 'file') continue; if (field.type === 'checkbox') field.checked = !!item[field.name]; else field.value = field.name.startsWith('details.') ? (item.details?.[field.name.slice(8)] ?? '') : (item[field.name] ?? ''); } editor.scrollIntoView({ behavior: 'smooth' }); }));
                    if (content) card.append(button('Remove', () => { if (confirm('Remove this item from publication?')) mutate(`${endpoint}/${item.id}`, 'DELETE'); }));
                    else if(module==='users') card.append(button('Send password reset', () => mutate(`${endpoint}/${item.id}/reset-password`, 'POST', {})));
                }
                list.append(card);
            }
            if (!result.data.length) list.append(node('p', 'No records yet.', 'panel'));
            last = result.meta?.last_page || result.last_page || 1; document.querySelector('#manage-page').textContent = `Page ${page} of ${last}`; document.querySelector('#manage-prev').disabled = page <= 1; document.querySelector('#manage-next').disabled = page >= last;
        } catch (e) { message.textContent = e.message; }
    }
    editor?.addEventListener('submit', async e => { e.preventDefault(); const id = editor.elements.id.value; try { const result = await api(editor.dataset.endpoint + (id ? `/${id}` : ''), id ? 'PATCH' : 'POST', formBody(editor)); if (content) editor.elements.id.value = result.data.id; if (content && editor.elements.image?.files[0]) await upload(`${editor.dataset.endpoint}/${result.data.id}/image`,editor.elements.image.files[0]);message.textContent='Saved.';await load(); } catch(error) {message.textContent=error.message;} });
    editor?.addEventListener('reset', () => { setTimeout(() => { editor.elements.id.value = ''; }, 0); });
    document.querySelector('#trash')?.addEventListener('change', () => { page = 1; load(); });
    document.querySelector('#manage-prev').onclick = () => { if (page > 1) { page--; load(); } }; document.querySelector('#manage-next').onclick = () => { if (page < last) { page++; load(); } }; load();
}

const carousel=document.querySelector('[data-carousel]');
if(carousel){const cards=[...carousel.querySelectorAll('article')];let current=0;const show=()=>cards.forEach((card,i)=>{card.hidden=i!==current;});if(cards.length>1){const controls=node('div',null,'flex gap-3 mb-4');controls.append(button('Previous infographic',()=>{current=(current+cards.length-1)%cards.length;show();}),button('Next infographic',()=>{current=(current+1)%cards.length;show();}));carousel.before(controls);show();}}
const publicMap=document.querySelector('[data-contact-map]');
if(publicMap?.dataset.key){window.cdrmPublicMapReady=()=>{const position={lat:Number(publicMap.dataset.latitude),lng:Number(publicMap.dataset.longitude)};const map=new google.maps.Map(publicMap,{center:position,zoom:14});new google.maps.Marker({map,position,title:'Official contact location'});};const script=document.createElement('script');const url=new URL('https://maps.googleapis.com/maps/api/js');url.searchParams.set('key',publicMap.dataset.key);url.searchParams.set('callback','cdrmPublicMapReady');url.searchParams.set('loading','async');script.src=url;script.onerror=()=>{publicMap.textContent='Map could not load. Please use the published contact address.';};document.head.append(script);}
