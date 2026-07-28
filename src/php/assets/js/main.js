import '../css/style.css';
import { initWs } from './websocket.js';
import { initTicker } from './components/ticker.js';
import { initKpiCards } from './components/kpi-cards.js';
import { initAiGauge } from './components/ai-gauge.js';
import { initMarket } from './components/market.js';
import { initGridLadder } from './components/grid-ladder.js';
import { initCharts } from './components/charts.js';
import { initTabbedPanel } from './components/tabbed-panel.js';
import { initLogViewer } from './components/log-viewer.js';

document.addEventListener('DOMContentLoaded', () => {
  initTicker();
  initKpiCards();
  initAiGauge();
  initMarket();
  initGridLadder();
  initCharts();
  initTabbedPanel();
  initLogViewer();
  initWs();
});
