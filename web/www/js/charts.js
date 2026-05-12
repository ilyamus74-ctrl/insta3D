// charts.js
// Отвечает за график (live и окно сигнала), метаданные справа/в модалке,
// и всю эту красоту, на которую будут потом смотреть и говорить "а чё не заработали миллионы?".

if (typeof window.Chart === 'undefined') {
  console.error('Chart.js не найден. Подключи chart.js до charts.js');
}

// Глобальное состояние
let charts = {
  desktop: null,
  mobile: null
};
let currentTarget = 'desktop';   // 'desktop' или 'mobile'
let currentMode = 'overview';    // 'overview' или 'signal'
let liveTimer = null;
let inFlight = null;

// Настройки API
const CONFIG = {
  overview: {
    url: 'https://easytrade.one/apiG.php',
    params: { action: 'price_recent', pair: 'EUR/USD', limit: 60 }
  },
  refreshMs: 30_000
};

// DOM refs не хардкодим наверху, а достаем через функции.
// Это упрощает жизнь для mobile/desktop.
function isMobile() {
  return window.matchMedia('(max-width: 768px)').matches;
}

// Возвращает DOM-элементы для текущей целевой панели (desktop или mobile)
function getTargets() {
  // десктоп
  const desktop = {
    canvas:    document.getElementById('priceChart'),
    metaWin:   document.getElementById('metaWindow'),
    meta: {
      ev:    document.getElementById('metaEvent'),
      entry: document.getElementById('metaEntry'),
      exit:  document.getElementById('metaExit'),
      delta: document.getElementById('metaDelta'),
      prob:  document.getElementById('metaProb'),
      fbId:  document.getElementById('fbSignalId')
    }
  };

  // мобилка (модалка)
  const mobile = {
    canvas:    document.getElementById('priceChartMobile') || desktop.canvas,
    metaWin:   document.getElementById('metaWindowM')      || desktop.metaWin,
    meta: {
      ev:    document.getElementById('metaEventM')   || desktop.meta.ev,
      entry: document.getElementById('metaEntryM')   || desktop.meta.entry,
      exit:  document.getElementById('metaExitM')    || desktop.meta.exit,
      delta: document.getElementById('metaDeltaM')   || desktop.meta.delta,
      prob:  document.getElementById('metaProbM')    || desktop.meta.prob,
      fbId:  document.getElementById('fbSignalIdM')  || desktop.meta.fbId
    }
  };

  return currentTarget === 'mobile' ? mobile : desktop;
}

// Хелперы форматирования
function fmtUtc(d) {
  const y  = d.getUTCFullYear();
  const m  = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dt = String(d.getUTCDate()).padStart(2, '0');
  const h  = String(d.getUTCHours()).padStart(2, '0');
  const mi = String(d.getUTCMinutes()).padStart(2, '0');
  const s  = String(d.getUTCSeconds()).padStart(2, '0');
  return `${y}-${m}-${dt} ${h}:${mi}:${s}`;
}

// Собираем URL с параметрами + bust cache
function buildUrl(base, params) {
  const usp = new URLSearchParams(params);
  usp.set('_', Date.now());
  return `${base}?${usp.toString()}`;
}

// Гасим предыдущий fetch, если он еще жив
function abortPrev() {
  if (inFlight) inFlight.abort();
  inFlight = null;
}

// Нормализация массива OHLC из API в формат для графика
function normalizeOhlcArray(arr) {
  const rows = Array.isArray(arr) ? arr : Object.values(arr || {});
  const tmp = [];

  for (const x of rows) {
    const tRaw = String(x.timestamp_utc || x.ts_utc || x.t || '');
    const t = tRaw
      ? +new Date(
          tRaw.indexOf('T') < 0
            ? tRaw.replace(' ','T')+'Z'
            : tRaw
        )
      : NaN;

    const o = Number(x.open  ?? x.o);
    const h = Number(x.high  ?? x.h);
    const l = Number(x.low   ?? x.l);
    const c = Number(x.close ?? x.c);

    if (Number.isFinite(t) && [o,h,l,c].every(Number.isFinite)) {
      tmp.push({ t, open:o, high:h, low:l, close:c });
    }
  }

  // сортировка по времени
  tmp.sort((a,b) => a.t - b.t);

  // дедуп по минуте (берём первое open, экстремумы high/low, последнее close)
  const dedup = [];
  for (const p of tmp) {
    const last = dedup[dedup.length-1];
    if (last && last.t === p.t) {
      last.high  = Math.max(last.high, p.high);
      last.low   = Math.min(last.low,  p.low);
      last.close = p.close;
      // open оставляем от первого
    } else {
      dedup.push({ ...p });
    }
  }

  return dedup;
}

// Инициализация конкретного графика (desktop/mobile)
function initChart(target = 'desktop') {
  const { canvas } = getTargets();
  if (!canvas) {
    console.error('canvas не найден для', target);
    return;
  }
  const ctx = canvas.getContext('2d');

  // Создаем новый Chart только если его нет
  if (!charts[target]) {
    charts[target] = new Chart(ctx, {
      type: 'candlestick', // chartjs-chart-financial уже зарегистрирован в HTML
      data: { datasets: [] },
      options: {
        responsive: true,
        animation: false,
        parsing: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                // ctx.raw = {x, o,h,l,c}
                const t = new Date(ctx.raw.x);
                const to5 = v => Number.isFinite(v) ? Number(v).toFixed(5) : 'N/A';
                return `${String(t.getUTCHours()).padStart(2,'0')}:`+
                       `${String(t.getUTCMinutes()).padStart(2,'0')} UTC | `+
                       `O:${to5(ctx.raw.o)} H:${to5(ctx.raw.h)} `+
                       `L:${to5(ctx.raw.l)} C:${to5(ctx.raw.c)}`;
              }
            }
          }
        },
        scales: {
          x: {
            type: 'time',
            time: {
              unit: 'minute',
              displayFormats: { minute: 'HH:mm' }
            },
            grid:  { color:'#1a2444' },
            ticks: { color:'#9fb2d4' }
          },
          y: {
            grid:  { color:'#1a2444' },
            ticks: {
              color:'#9fb2d4',
              callback:v => Number(v).toFixed(5)
            }
          }
        }
      }
    });
  }
}

// Полная очистка графика и панелей с метаинфой
function wipeChartAndMeta() {
  const { meta } = getTargets();

  if (meta.ev)    meta.ev.textContent    = '—';
  if (meta.entry) meta.entry.textContent = '—';
  if (meta.exit)  meta.exit.textContent  = '—';
  if (meta.delta) meta.delta.textContent = '—';
  if (meta.prob)  meta.prob.textContent  = '—';
  if (meta.fbId)  meta.fbId.value        = '';

  const chart = charts[currentTarget];
  if (chart) {
    chart.data.datasets = [];
    chart.update('none');
  }
}

// Заполнить правую панель/модалку конкретным сигналом
function fillMeta(sigObj) {
  const { meta } = getTargets();

  const entry = Number(sigObj.price_entry);
  const exit  = Number(sigObj.price_exit);
  const delta = (Number.isFinite(entry) && Number.isFinite(exit))
    ? ((exit - entry) * 10000)
    : null;

  if (meta.ev)    meta.ev.textContent    = sigObj.event || '—';
  if (meta.entry) meta.entry.textContent = Number.isFinite(entry) ? entry.toFixed(5) : '—';
  if (meta.exit)  meta.exit.textContent  = Number.isFinite(exit)  ? exit.toFixed(5)  : '—';
  if (meta.delta) meta.delta.textContent = (delta !== null) ? delta.toFixed(3) : '—';
  if (meta.prob)  meta.prob.textContent  = (sigObj.prob != null)
                                          ? `${Math.round(sigObj.prob)}%`
                                          : '—';
  if (meta.fbId)  meta.fbId.value        = sigObj.id || '';
}

// Рендер свечек в график + точки входа/выхода
function renderCandles(series, markers = {}) {
  const chart = charts[currentTarget];
  if (!chart) return;

  if (!Array.isArray(series) || series.length === 0) {
    // нет данных: чистим график, но мету не трогаем тут
    chart.data.datasets = [];
    chart.update('none');
    return;
  }

  const ds = [{
    label: 'EUR/USD',
    data: series.map(p => ({
      x: (typeof p.t === 'number') ? p.t : +new Date(p.t),
      o: p.open,
      h: p.high,
      l: p.low,
      c: p.close
    })),
    // chartjs-chart-financial понимает эти цвета
    color: {
      up:        '#2ecc71',
      down:      '#e74c3c',
      unchanged: '#9fb2d4'
    },
    borderColor: {
      up:        '#2ecc71',
      down:      '#e74c3c',
      unchanged: '#9fb2d4'
    },
    wickColor: {
      up:        '#2ecc71',
      down:      '#e74c3c',
      unchanged: '#9fb2d4'
    },
    barThickness: 6
  }];

  // маркер входа
  if (markers.entry && Number.isFinite(markers.entry.y) && markers.entryTime) {
    ds.push({
      type: 'scatter',
      label: 'Entry',
      data: [{ x:+markers.entryTime, y:markers.entry.y }],
      pointBackgroundColor:'#00ff6e',
      pointRadius:6,
      pointStyle:'circle'
    });
  }

  // маркер выхода
  if (markers.exit && Number.isFinite(markers.exit.y) && markers.exitTime) {
    ds.push({
      type: 'scatter',
      label: 'Exit',
      data: [{ x:+markers.exitTime, y:markers.exit.y }],
      pointBackgroundColor:'#ff0000',
      pointRadius:6,
      pointStyle:'circle'
    });
  }

  chart.data.datasets = ds;
  chart.update('none');
}

// грузим короткий live график последних N минут
async function fetchOverview() {
  try {
    abortPrev();
    inFlight = new AbortController();

    const url = buildUrl(CONFIG.overview.url, CONFIG.overview.params);
    const resp = await fetch(url, { cache: 'no-store', signal: inFlight.signal });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();

    const series = normalizeOhlcArray(data.items || data || []);
    // если пусто, просто стираем график
    if (!series.length) {
      wipeChartAndMeta();
      return;
    }

    // это лайв, тут нет сигнала, просто свечи
    renderCandles(series);
  } catch (e) {
    if (e.name !== 'AbortError') console.error('overview fetch failed:', e);
  }
}

// включаем режим "обзор последних 60 минут"
function startLiveOverview() {
  currentMode = 'overview';
  const { metaWin } = getTargets();
  if (metaWin) metaWin.textContent = 'останні 60 хв';

  clearInterval(liveTimer);
  fetchOverview();
  liveTimer = setInterval(fetchOverview, CONFIG.refreshMs);
}

// грузим полный пакет по сигналу из API
async function loadSignalById(id, model) {
  try {
    const url = buildUrl('https://easytrade.one/apiG.php', {
      action: 'signal_full',
      id,
      model,
      window_min: 30
    });

    const resp = await fetch(url, { cache: 'no-store' });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const js = await resp.json();
    if (!js || !js.ok) throw new Error('bad payload');

    const series = normalizeOhlcArray(js.candles || []);

    return {
      id,
      model,
      series,
      ts_utc: js.graph_meta?.ts_utc || js.live?.ts_utc || '',
      price_entry: js.graph_meta?.price_entry ?? js.live?.price_entry ?? null,
      price_exit:  js.graph_meta?.price_exit  ?? js.live?.price_exit  ?? null,
      event: js.live?.event || '',
      prob: js.live?.direction_prob
        ? (Number(js.live.direction_prob) * 100)
        : null
    };

  } catch (e) {
    console.error('loadSignalById failed:', e);
    return null;
  }
}

// показать окно сигнала: график +-30 минут и мету
function showSignalWindow(sigFullObj) {
  clearInterval(liveTimer);
  currentMode = 'signal';

  const { metaWin } = getTargets();
  if (metaWin) metaWin.textContent = '−30 / +30 хв';

  if (!sigFullObj || !Array.isArray(sigFullObj.series) || sigFullObj.series.length === 0) {
    // данных нет. чистим всё.
    wipeChartAndMeta();
    return;
  }

  // маркеры для входа / выхода
  const entryTime = sigFullObj.ts_utc
    ? new Date(sigFullObj.ts_utc.replace(' ', 'T') + 'Z')
    : null;
  const exitTime = entryTime
    ? new Date(entryTime.getTime() + 30 * 60 * 1000)
    : null;

  renderCandles(sigFullObj.series, {
    entry: Number.isFinite(+sigFullObj.price_entry)
      ? { y: +sigFullObj.price_entry }
      : null,
    exit:  Number.isFinite(+sigFullObj.price_exit)
      ? { y: +sigFullObj.price_exit }
      : null,
    entryTime,
    exitTime
  });

  // автообновление: только если окно еще не закончилось (текущая цена может двигаться)
  if (exitTime && exitTime > new Date()) {
    liveTimer = setInterval(async () => {
      const upd = await loadSignalById(sigFullObj.id, sigFullObj.model);
      if (!upd || !Array.isArray(upd.series) || upd.series.length === 0) {
        wipeChartAndMeta();
        return;
      }
      const et = upd.ts_utc
        ? new Date(upd.ts_utc.replace(' ', 'T') + 'Z')
        : null;
      const xt = et
        ? new Date(et.getTime() + 30 * 60 * 1000)
        : null;
      renderCandles(upd.series, {
        entry: Number.isFinite(+upd.price_entry)
          ? { y: +upd.price_entry }
          : null,
        exit:  Number.isFinite(+upd.price_exit)
          ? { y: +upd.price_exit }
          : null,
        entryTime: et,
        exitTime:  xt
      });
      fillMeta(upd);
    }, CONFIG.refreshMs);
  }
}

// Главный запуск
document.addEventListener('DOMContentLoaded', () => {
  const table      = document.getElementById('signalsTable');
  const modelSel   = document.getElementById('modelSelect');
  const signalModalEl = document.getElementById('signalModal');

  // выбрать первоначальную цель (desktop/mobile)
  currentTarget = isMobile() ? 'mobile' : 'desktop';

  // инициализируем нужный график
  initChart(currentTarget);
  startLiveOverview(); // по дефолту показываем последние 60 минут

  // слушаем ресайз экрана и при переходе в другой таргет лениво создаем второй график
  window.addEventListener('resize', () => {
    const newTarget = isMobile() ? 'mobile' : 'desktop';
    if (newTarget !== currentTarget) {
      currentTarget = newTarget;
      initChart(currentTarget);
      // при смене панели перерисуем текущее состояние по режиму
      if (currentMode === 'overview') {
        startLiveOverview();
      }
      // если был открыт сигнал, то график сам перерисуем при клике снова
    }
  });

  // обработчик клика по строке таблицы
  if (table) {
    table.addEventListener('click', async (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;

      // подсветка строки
      table.querySelectorAll('tbody tr.row-active').forEach(r => r.classList.remove('row-active'));
      tr.classList.add('row-active');

      const id = tr.getAttribute('data-id');
      const model = (modelSel && modelSel.value) ? modelSel.value : 'ET3';

      // mobile сценарий: показываем модалку
      if (isMobile()) {
        currentTarget = 'mobile';
        initChart('mobile');

        const bsModal = bootstrap.Modal.getOrCreateInstance(signalModalEl);
        bsModal.show();

        signalModalEl.addEventListener('shown.bs.modal', () => {
          // иногда канвас в модалке сначала 0x0
          if (charts.mobile) charts.mobile.resize();
        }, { once:true });
      } else {
        // десктоп: просто скроллим к графику
        currentTarget = 'desktop';
        initChart('desktop');
        document.getElementById('chartCard')?.scrollIntoView({
          behavior:'smooth',
          block:'nearest'
        });
      }

      // грузим данные сигнала
      const sigFull = await loadSignalById(id, model);
      if (!sigFull) {
        wipeChartAndMeta();
        return;
      }

      // рендерим график и мету
      showSignalWindow(sigFull);
      fillMeta(sigFull);
    });
  }
});

