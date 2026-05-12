document.addEventListener('DOMContentLoaded', () => {
  const table        = document.getElementById('signalsTable');
  const tbody        = table ? table.querySelector('tbody') : null;
  const signalsCount = document.getElementById('signalsCount');

  const modelSelect  = document.getElementById('modelSelect');
  const dateFromEl   = document.getElementById('dateFrom');
  const dateToEl     = document.getElementById('dateTo');
  const dirFilterEl  = document.getElementById('dirFilter');

  // детали
  const metaEntry = document.getElementById('metaEntry');
  const metaExit  = document.getElementById('metaExit');
  const metaDelta = document.getElementById('metaDelta');
  const metaProb  = document.getElementById('metaProb');
  const metaEvent = document.getElementById('metaEvent');

  const REFRESH_MS = 30_000;

  if (!tbody) {
    console.error('tbody для #signalsTable не найден');
    return;
  }

  let lastHash  = '';
  let inFlight  = null;
  let lastRows  = [];     // сырые строки последнего ответа
  let selectedId = null;  // текущая выделенная строка

  // init
  fetchAndRender();
  setInterval(fetchAndRender, REFRESH_MS);
  if (modelSelect) modelSelect.addEventListener('change', fetchAndRender);



  // фильтры
  [modelSelect, dateFromEl, dateToEl, dirFilterEl].forEach(el => {
    if (el) el.addEventListener('change', applyFiltersAndRender);
  });

  // клик по таблице -> показать детали
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    selectedId = tr.getAttribute('data-id');
    highlightSelectedRow();
    const row = (lastRows || []).find(r => String(r.id) === String(selectedId));
    if (row) fillDetails(row);
  });

  // -------- fetch ----------
  function buildApiUrl() {
    const params = new URLSearchParams();
    params.set('action', 'signals');
    const model = (modelSelect && modelSelect.value) ? modelSelect.value : '';
    if (model) params.set('model', model);
    params.set('_', Date.now()); // bust cache
    return `https://easytrade.one/apiG.php?${params.toString()}`;
    
  }

  async function fetchAndRender() {
    try {
      if (inFlight) inFlight.abort();
      inFlight = new AbortController();

      const url  = buildApiUrl();
//      console.log(url);
      const resp = await fetch(url, { cache: 'no-store', signal: inFlight.signal });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
//      console.log(resp);
      const data = await resp.json();
      const rows = normalize(data.items);

      // хэш по id+updated_at
      const clientHash = await hashHex(rows.map(r => `${r.id}|${r.updated_at||''}`).join('#'));
      if (clientHash === lastHash) {
        // данные те же — но фильтры могли измениться, перерисуем отфильтрованное
        lastRows = rows;
        applyFiltersAndRender();
        return;
      }
      lastHash = clientHash;
      lastRows = rows;
//      console.log(lastRows);
      applyFiltersAndRender();
    } catch (e) {
      if (e.name !== 'AbortError') console.error('Не удалось получить сигналы:', e);
    }
  }

  // -------- фильтрация + рендер ----------
  function applyFiltersAndRender() {
    const filtered = filterRows(lastRows);
    filtered.sort((a,b) => new Date(b.ts_iso || b.ts_utc) - new Date(a.ts_iso || a.ts_utc));
    renderTable(filtered);
//    console.log(filtered);
    // если выделенная строка отвалилась из-за фильтра — сбросить детали
    if (!filtered.some(r => String(r.id) === String(selectedId))) {
      selectedId = null;
      clearDetails();
    }
  }


function filterRows(rows) {
  const dirVRaw = dirFilterEl && dirFilterEl.value !== '' ? Number(dirFilterEl.value) : null;
  const fromV  = dateFromEl && dateFromEl.value ? new Date(`${dateFromEl.value}T00:00:00Z`) : null;
  const toV    = dateToEl && dateToEl.value ? new Date(`${dateToEl.value}T23:59:59Z`) : null;

  // НОВОЕ: выбранная модель
  const wantedModel = (modelSelect && modelSelect.value) ? String(modelSelect.value).toUpperCase() : '';

 // если фильтр задан в шкале -1/0/1 (старый UI), маппим в 0/1/2
  const dirV = (dirVRaw === -1) ? 0 : (dirVRaw === 0) ? 1 : (dirVRaw === 1) ? 2 : dirVRaw;
  return (rows || []).filter(r => {
    // модель
    ///////if (wantedModel && String(r.model_tag || '').toUpperCase() !== wantedModel) return false;

    // направление
    if (dirV !== null && Number(r.direction_pred) !== dirV) return false;
    // даты
    const t = r.ts_iso ? new Date(r.ts_iso)
                       : (r.ts_utc ? new Date(r.ts_utc.replace(' ', 'T') + 'Z') : null);
    if (!t) return false;
    if (fromV && t < fromV) return false;
    if (toV   && t > toV)   return false;

    return true;
  });
}

  function renderTable(rows) {
    const html = (rows || []).map(r => {
      const ts   = (r.ts_iso || r.ts_utc || '').replace('T',' ').replace('Z','');
      const dir  = (r.direction_pred === 2) ? 'Up' : (r.direction_pred === 0) ? 'Down' : 'Flat';
      const prob = isFinite(r.direction_prob) ? `${Math.round(Number(r.direction_prob)*100)}%` : '';
      const pips = isFinite(r.magnitude_pred) ? Number(r.magnitude_pred).toFixed(3) : '';
//      const news = isFinite(r.is_removed) ? Number(r.magnitude_pred).toFixed(3) : '';
////      const news = (r.is_removed == 1) ? 'Del' : 'Pre';
//      const news = r.is_removed;
      const prioClass = (r.priority === 'High') ? 'badge-high' : 'badge-low';

      const ev = String(r.event || '');
      const paren = ev.indexOf('(');
      const title = paren > 0 ? ev.slice(0, paren).trim() : ev;
      const codes = paren > 0 ? ev.slice(paren).trim() : '';

      const active = String(r.id) === String(selectedId) ? 'row-active' : '';

      return `
        <tr data-id="${escapeHtml(String(r.id))}" class="align-middle ${active}">
          <td class="col-utc">${escapeHtml(ts)}</td>
          <td class="col-ev">
            <span class="ev-wrap">
              <span class="ev-title">${escapeHtml(title)}</span>
              ${codes ? `<span class="ev-codes">${escapeHtml(codes)}</span>` : ''}
            </span>
          </td>
          <td class="col-dir">${dir}</td>
          <td class="col-prob">${prob}</td>
          <td class="col-pips">${pips}</td>
          <td class="col-prio"><span class="badge ${prioClass}">${escapeHtml(r.priority || '')}</span></td>
        </tr>`;
////          <td class="col-pips">${news}</td>

    }).join('');

    tbody.innerHTML = html;
    if (signalsCount) signalsCount.textContent = `${rows.length} записів`;
  }

  function fillDetails(r) {
  if (metaEvent) {
  // короткий и «коды»
  const ev = String(r.event || '');
  const paren = ev.indexOf('(');
  const title = paren > 0 ? ev.slice(0, paren).trim() : ev;
  const codes = paren > 0 ? ev.slice(paren).trim() : '';
  metaEvent.innerHTML = `${escapeHtml(title)}${codes ? `<div class="muted" style="font-size:.9em">${escapeHtml(codes)}</div>` : ''}`;
}
    metaEntry.textContent = isFinite(r.price_entry) ? Number(r.price_entry).toFixed(5) : '—';
    metaExit.textContent  = isFinite(r.price_exit)  ? Number(r.price_exit).toFixed(5)  : '—';
    const deltaPips = (isFinite(r.price_entry) && isFinite(r.price_exit))
      ? Math.abs((Number(r.price_exit) - Number(r.price_entry)) * 10000).toFixed(1)
      : '—';
    metaDelta.textContent = deltaPips;
    metaProb.textContent  = isFinite(r.direction_prob) ? `${Math.round(Number(r.direction_prob)*100)}%` : '—';
  }

  function clearDetails() {
  if (metaEvent) metaEvent.textContent = '—';
    metaEntry.textContent = '—';
    metaExit.textContent  = '—';
    metaDelta.textContent = '—';
    metaProb.textContent  = '—';
  }

  function highlightSelectedRow() {
    Array.from(tbody.querySelectorAll('tr')).forEach(tr => tr.classList.remove('row-active'));
    const active = tbody.querySelector(`tr[data-id="${CSS.escape(String(selectedId))}"]`);
    if (active) active.classList.add('row-active');
  }

  // ---------- utils ----------
  function normalize(items) {
    const arr = Array.isArray(items) ? items : Object.values(items || {});
    return arr.map(x => {
      const ts = String(x.ts_utc || '');
      // поддержка обеих схем бэкенда:
      //  a) direction_pred=0/1/2
      //  b) dir=-1/0/1  (=> 0/1/2)
      const hasDirPred = x.hasOwnProperty('direction_pred');
      const hasDir     = x.hasOwnProperty('dir');
      const dirMapped  = hasDir
        ? (Number(x.dir) === -1 ? 0 : Number(x.dir) === 1 ? 2 : 1)
        : NaN;

      const direction_pred = hasDirPred ? Number(x.direction_pred) : dirMapped;
      const direction_prob = (x.direction_prob !== undefined) ? Number(x.direction_prob)
                             : (x.prob !== undefined) ? Number(x.prob) : NaN;
      const magnitude_pred = (x.magnitude_pred !== undefined) ? Number(x.magnitude_pred)
                               : (x.mag_pips !== undefined) ? Number(x.mag_pips) : NaN;
      const price_entry    = (x.price_entry !== undefined) ? Number(x.price_entry)
                               : (x.entry !== undefined) ? Number(x.entry) : NaN;
      const price_exit     = (x.price_exit !== undefined) ? Number(x.price_exit)
                               : (x.exit !== undefined) ? Number(x.exit) : NaN;

      return {
        id:             num(x.id),
        ts_utc:         ts,
        ts_iso:         toIso(ts),
        event:          str(x.event),
        direction_pred: num(direction_pred),
        direction_prob: num(direction_prob),
        magnitude_pred: num(magnitude_pred),
        price_entry:    num(price_entry),
        price_exit:     num(price_exit),
        currency_pair:  str(x.currency_pair),
        priority:       str(x.priority) || 'Low',
        is_removed:     num(x.is_removed),
        model_tag:      str(x.model_tag),
        updated_at:     str(x.updated_at)
      };
    });
  }

  function num(v){ const n = Number(v); return Number.isFinite(n) ? n : NaN; }
  function str(v){ return (v === null || v === undefined) ? '' : String(v); }
  function toIso(ts){ return ts && ts.indexOf('T') < 0 ? ts.replace(' ', 'T') + 'Z' : ts; }
  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
  async function hashHex(text){
    const enc = new TextEncoder().encode(text);
    const buf = await crypto.subtle.digest('SHA-256', enc);
    const view = new Uint8Array(buf);
    return Array.from(view).map(b => b.toString(16).padStart(2,'0')).join('');
  }
});

