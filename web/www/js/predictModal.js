// breakpoint как у Bootstrap lg: < 992px = мобильный/планшет
const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;

let charts = { desktop: null, mobile: null };
let currentTarget = 'desktop'; // 'desktop' | 'mobile'

// вернём нужные DOM-элементы
function getTargets() {
  return currentTarget === 'mobile'
    ? {
        canvas: document.getElementById('priceChartMobile'),
        metaWin: document.getElementById('metaWindowM'),
        meta: {
          ev: document.getElementById('metaEventM'),
          entry: document.getElementById('metaEntryM'),
          exit: document.getElementById('metaExitM'),
          delta: document.getElementById('metaDeltaM'),
          prob: document.getElementById('metaProbM'),
          fbId: document.getElementById('fbSignalIdM')
        }
      }
    : {
        canvas: document.getElementById('priceChart'),
        metaWin: document.getElementById('metaWindow'),
        meta: {
          ev: document.getElementById('metaEvent'),
          entry: document.getElementById('metaEntry'),
          exit: document.getElementById('metaExit'),
          delta: document.getElementById('metaDelta'),
          prob: document.getElementById('metaProb'),
          fbId: document.getElementById('fbSignalId')
        }
      };
}
