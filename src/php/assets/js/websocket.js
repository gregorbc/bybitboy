/*
  WebSocket connection manager.
  Connects to ws://HOST:8082, dispatches CustomEvents on window.
  Falls back to HTTP polling if connection fails.
*/

const WS_PORT = 8082;
const POLL_INTERVAL = 2000;
const RECONNECT_DELAY = 3000;

let ws = null;
let pollTimer = null;
let isPolling = false;

function getWsUrl() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  return `${proto}://${location.hostname}:${WS_PORT}`;
}

function dispatch(type, data) {
  window.dispatchEvent(new CustomEvent(type, { detail: data }));
}

function onWsMessage(event) {
  try {
    const d = JSON.parse(event.data);
    if (d.type === 'full') {
      // Map flat WS payload to typed events
      if (d.ticker) dispatch('data:ticker', d.ticker);
      dispatch('data:grid', { orders: d.orders || [], mode: d.mode, open_orders: d.open_orders });
      dispatch('data:kpi', {
        pnl_today:    d.pair?.pnl_today || 0,
        pnl_total:    d.pair?.pnl_total || 0,
        win_rate:     d.win_rate || 0,
        uptime_sec:   d.uptime || 0,
        total_upnl:   d.total_upnl || 0,
        real_balance: d.real_balance || 0,
        maker_fee:    d.makerFee || 0.0001,
        taker_fee:    d.takerFee || 0.0006,
      });
      dispatch('data:ai', {
        direction:   d.pair?.direction || 'SIDEWAYS',
        confidence:  d.pair?.confidence || 0,
        reason:      d.pair?.ai_reason || '',
        next_eval:   d.pair?.last_ai_check || null,
      });
      if (d.ticker) dispatch('data:market', {
        rsi:       d.pair?.rsi || null,
        macd:      d.pair?.macd || null,
        adx:       d.pair?.adx || null,
        atr:       d.ticker?.atr_pct || null,
        bollinger: d.pair?.bollinger_pct || null,
        ema9:      d.ticker?.ema9 || null,
        ema21:     d.ticker?.ema21 || null,
        ema50:     d.ticker?.ema50 || null,
        funding:   d.ticker?.fundRate || 0,
        oi:        d.ticker?.oi || 0,
      });
      if (d.positions) dispatch('data:positions', d.positions);
      if (d.recent_fills) dispatch('data:fills', d.recent_fills);
      if (d.logs) dispatch('data:logs', d.logs);
      if (d.pnl_hourly || d.pnl_cumulative) {
        dispatch('data:pnl', {
          hourly:     d.pnl_hourly || [],
          daily:      d.pnl_daily || [],
          cumulative: d.pnl_cumulative || [],
        });
      }
    }
  } catch (_) { /* ignore malformed */ }
}

function connectWs() {
  try {
    ws = new WebSocket(getWsUrl());
    ws.onopen = () => { dispatch('ws:status', { connected: true }); stopPolling(); };
    ws.onclose = () => { dispatch('ws:status', { connected: false }); ws = null; startPolling(); setTimeout(connectWs, RECONNECT_DELAY); };
    ws.onerror = () => { ws = null; };
    ws.onmessage = onWsMessage;
  } catch (_) {
    ws = null;
    startPolling();
  }
}

async function pollAll() {
  try {
    const { default: { api } } = await import('./api.js');
    const d = await api('_status');
    // Same mapping as WS onWsMessage
    if (d.ticker) dispatch('data:ticker', d.ticker);
    dispatch('data:grid', { orders: d.orders || [], mode: d.mode, open_orders: d.open_orders });
    dispatch('data:kpi', {
      pnl_today: d.pair?.pnl_today || 0, pnl_total: d.pair?.pnl_total || 0,
      win_rate: d.win_rate || 0, uptime_sec: d.uptime || 0,
    });
    dispatch('data:ai', {
      direction: d.pair?.direction || 'SIDEWAYS', confidence: d.pair?.confidence || 0,
      reason: d.pair?.ai_reason || '', next_eval: d.pair?.last_ai_check || null,
    });
    if (d.positions) dispatch('data:positions', d.positions);
    if (d.recent_fills) dispatch('data:fills', d.recent_fills);
    if (d.logs) dispatch('data:logs', d.logs);
  } catch (_) { /* silent */ }
}

function startPolling() {
  if (isPolling) return;
  isPolling = true;
  pollAll();
  pollTimer = setInterval(pollAll, POLL_INTERVAL);
}

function stopPolling() {
  isPolling = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

export function initWs() {
  connectWs();
}

export function closeWs() {
  if (ws) { ws.close(); ws = null; }
  stopPolling();
}
