import { $, clear } from '../utils/dom.js';
import { fmtCurrency, fmtTime } from '../utils/format.js';

let pnlHourlyChart = null;
let pnlDailyChart = null;
let pnlCumulativeChart = null;
let candleChart = null;

export function initCharts() {
  initPnLCharts();
  initCandleChart();
  attachDataListeners();
}

function initPnLCharts() {
  const hourlyCtx = $('#chart-pnl-hourly');
  const dailyCtx = $('#chart-pnl-daily');
  const cumulativeCtx = $('#chart-pnl-cumulative');

  if (hourlyCtx && typeof Chart !== 'undefined') {
    pnlHourlyChart = new Chart(hourlyCtx, {
      type: 'bar',
      data: { labels: [], datasets: [{ label: 'PnL Hourly', data: [], backgroundColor: [], borderRadius: 4 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
  if (dailyCtx && typeof Chart !== 'undefined') {
    pnlDailyChart = new Chart(dailyCtx, {
      type: 'bar',
      data: { labels: [], datasets: [{ label: 'PnL Daily', data: [], backgroundColor: [], borderRadius: 4 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
  if (cumulativeCtx && typeof Chart !== 'undefined') {
    pnlCumulativeChart = new Chart(cumulativeCtx, {
      type: 'line',
      data: { labels: [], datasets: [{ label: 'Cumulative PnL', data: [], borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,0.1)', fill: true, tension: 0.3, pointRadius: 2 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
}

function initCandleChart() {
  const container = $('#candle-chart');
  if (!container || typeof window.LightweightCharts === 'undefined') return;
  candleChart = window.LightweightCharts.createChart(container, {
    layout: { background: { color: 'transparent' }, textColor: '#94a3b8' },
    grid: { vertLines: { color: '#1e3a5f' }, horzLines: { color: '#1e3a5f' } },
    width: container.clientWidth,
    height: 300,
  });
  const candleSeries = candleChart.addCandlestickSeries({ upColor: '#22c55e', downColor: '#ef4444', borderVisible: false, wickUpColor: '#22c55e', wickDownColor: '#ef4444' });
  candleChart._series = candleSeries;

  window.addEventListener('data:candles', (e) => {
    const candles = e.detail;
    if (candles && candles.length) {
      candleSeries.setData(candles.map(c => ({ time: c.time || c.t, open: c.open || c.o, high: c.high || c.h, low: c.low || c.l, close: c.close || c.c })));
    }
  });
}

function attachDataListeners() {
  window.addEventListener('data:pnl', (e) => {
    const { hourly, daily, cumulative } = e.detail;
    if (pnlHourlyChart && hourly) updateBarChart(pnlHourlyChart, hourly);
    if (pnlDailyChart && daily) updateBarChart(pnlDailyChart, daily);
    if (pnlCumulativeChart && cumulative) updateLineChart(pnlCumulativeChart, cumulative);
  });
}

function updateBarChart(chart, data) {
  chart.data.labels = data.map(d => d.label || '');
  chart.data.datasets[0].data = data.map(d => d.value);
  chart.data.datasets[0].backgroundColor = data.map(d => d.value >= 0 ? 'rgba(34,197,94,0.5)' : 'rgba(239,68,68,0.5)');
  chart.update();
}

function updateLineChart(chart, data) {
  chart.data.labels = data.map(d => d.label || '');
  chart.data.datasets[0].data = data.map(d => d.value);
  chart.update();
}

window.addEventListener('resize', () => {
  if (candleChart) {
    const container = $('#candle-chart');
    if (container) candleChart.resize(container.clientWidth, 300);
  }
});
