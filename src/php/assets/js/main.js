import '../css/style.css';
import { initWs } from './websocket.js';
import { initTicker } from './components/ticker.js';
import { initKpiCards } from './components/kpi-cards.js';
import { initAiGauge } from './components/ai-gauge.js';
import { initMarket } from './components/market.js';

document.addEventListener('DOMContentLoaded', () => {
  initTicker();
  initKpiCards();
  initAiGauge();
  initMarket();
  initWs();
});
