// Main Dashboard Application

import { state } from '@/state';
import { api } from '@/api/client';
import { getWebSocketClient } from '@/websocket/client';
import {
  createHourlyPnLChart,
  createDailyPnLChart,
  createCumulativePnLChart,
  updateHourlyPnLChart,
  updateDailyPnLChart,
  updateCumulativePnLChart,
} from '@/charts/pnl';
import { renderOrderLadder, updateOrderLadder } from '@/components/order-ladder';
import { renderMarketIndicators } from '@/components/market';
import { renderFillsTable, setFillsData, prependFills, loadFillsHistory, fillsPrev, fillsNext, getFillsCount } from '@/components/fills';
import { renderMlFeatures } from '@/components/ml-features';
import { setLogs, prependLogs, setLogFilter, setLogPaused, clearLogs } from '@/components/logs';
import { renderAiGauge, startAiCountdown, resetAiCountdown, showToast, stopAiCountdown } from '@/components/ai-gauge';
import { formatPrice, formatMoney, formatPct, formatNumber, formatQty, formatUptime, isInViewport, sleep, getPnLColor } from '@/utils/format';
import type { Ticker, BotStatus, PairStatus, Order, Fill, Position, PnLData, MarketIndicators, MlInfo, LogEntry } from '@/types';

// Make functions globally available for inline handlers
(window as any).fillsPrev = fillsPrev;
(window as any).fillsNext = fillsNext;
(window as any).loadFillsHistory = loadFillsHistory;
(window as any).toggleLogPause = () => {
  const current = state.getState().logPaused;
  setLogPaused(!current);
};

class DashboardApp {
  private ws: ReturnType<typeof getWebSocketClient> | null = null;
  private pollingTimers: Map<string, ReturnType<typeof setInterval>> = new Map();
  private charts: Map<string, any> = new Map();
  private unsubscribers: (() => void)[] = [];
  private initialized = false;

  async init(): Promise<void> {
    if (this.initialized) return;

    // Load constants from PHP (injected via meta tags or global)
    this.loadConstants();

    // Subscribe to state changes
    this.setupStateSubscriptions();

    // Initialize WebSocket
    this.initWebSocket();

    // Start polling as fallback
    this.startPolling();

    // Initialize UI event handlers
    this.initEventHandlers();

    // Initialize charts
    this.initCharts();

    // Request notification permission
    this.requestNotificationPermission();

    this.initialized = true;
    console.log('[App] Dashboard initialized');
  }

  private loadConstants(): void {
    const capitalCfg = parseFloat((document.querySelector('meta[name="capital-cfg"]') as HTMLMetaElement)?.content || '20');
    const aiInterval = parseInt((document.querySelector('meta[name="ai-interval"]') as HTMLMetaElement)?.content || '120', 10);
    const ctrlToken = (document.querySelector('meta[name="ctrl-token"]') as HTMLMetaElement)?.content || '';
    const exportToken = (document.querySelector('meta[name="export-token"]') as HTMLMetaElement)?.content || '';

    state.setConstants({ capitalCfg, aiInterval, ctrlToken, exportToken });
    startAiCountdown(aiInterval);
  }

  private setupStateSubscriptions(): void {
    this.unsubscribers.push(state.subscribe(s => {
      if (s.ticker) this.updateTickerUI(s.ticker);
      if (s.botStatus) this.updateBotStatus(s.botStatus);
      if (s.pairStatus) this.updatePairUI(s.pairStatus);
      if (s.marketIndicators) renderMarketIndicators(s.marketIndicators);
      if (s.orders) updateOrderLadder(document.getElementById('ladderWrap')!, s.orders, s.ticker?.lastPrice || 0);
      if (s.recentFills) this.updateRecentFills(s.recentFills);
      if (s.positions) this.updatePositions(s.positions, s.totalUpnl);
      if (s.pnlData) this.updatePnLCharts(s.pnlData);
      if (s.mlInfo) renderMlFeatures(document.getElementById('mlFeatBars')!, s.mlInfo);
      if (s.mlFeatures) this.updateMlFeatures(s.mlFeatures);
      if (s.logs) setLogs(s.logs);
    }));
  }

  private initWebSocket(): void {
    const exportToken = state.getState().exportToken;
    if (!exportToken) {
      console.warn('[App] No export token for WebSocket');
      return;
    }

    this.ws = getWebSocketClient(exportToken);
    this.ws.setCallbacks({
      onTicker: (data) => state.setTicker(data),
      onStatus: (data) => state.setBotStatus(data),
      onPair: (data) => state.setPairStatus(data),
      onOrders: (data) => state.setOrders(data),
      onFills: (data) => {
        state.setRecentFills(data);
        prependFills(data);
      },
      onPositions: (data) => {
        const totalUpnl = data.reduce((sum, p) => sum + (p.unRealizedProfit || 0), 0);
        state.setPositions(data, totalUpnl);
      },
      onPnL: (data) => state.setPnLData(data),
      onPnLHourly: (data) => {
        if (state.getState().pnlData) {
          state.setPnLHourly(data);
        }
      },
      onPnLCumulative: (data) => {
        if (state.getState().pnlData) {
          state.setPnLCumulative(data);
        }
      },
      onConfidence: (data) => setLogs(data),
      onLogs: (data) => prependLogs(data),
      onMarketIndicators: (data) => state.setMarketIndicators(data),
      onMlFeatures: (data) => state.setMlFeatures(data),
      onConnect: () => state.setWsConnected(true),
      onDisconnect: () => state.setWsConnected(false),
      onError: (err) => console.error('[WS] Error:', err),
    });

    this.ws.connect();
  }

  private startPolling(): void {
    const { fast, normal } = {
      fast: { tick: 1000, stat: 3000, log: 4000, mkt: 30000, upnl: 2500, scalp: 15000 },
      normal: { tick: 2000, stat: 5000, log: 8000, mkt: 60000, upnl: 5000, scalp: 30000 },
    };

    const getIntervals = () => state.getState().speedMode === 'fast' ? fast : normal;

    this.pollingTimers.set('ticker', setInterval(() => this.fetchTicker(), getIntervals().tick));
    this.pollingTimers.set('status', setInterval(() => this.fetchStatus(), getIntervals().stat));
    this.pollingTimers.set('market', setInterval(() => this.fetchMarket(), getIntervals().mkt));
    this.pollingTimers.set('upnl', setInterval(() => this.fetchUpnl(), getIntervals().upnl));
    this.pollingTimers.set('scalp', setInterval(() => this.fetchScalp(), getIntervals().scalp));
    this.pollingTimers.set('logs', setInterval(() => this.fetchLogs(), getIntervals().log));
    this.pollingTimers.set('ml', setInterval(() => this.fetchMLInfo(), 60000));

    this.unsubscribers.push(state.subscribe(s => {
      if (s.speedMode) {
        this.restartPolling();
        startAiCountdown(s.aiInterval);
      }
    }));
  }

  private restartPolling(): void {
    this.pollingTimers.forEach(timer => clearInterval(timer));
    this.pollingTimers.clear();
    this.startPolling();
  }

  private async fetchTicker(): Promise<void> {
    const data = await api.getTicker();
    if (data) state.setTicker(data);
  }

  private async fetchStatus(): Promise<void> {
    const data = await api.getStatus();
    if (data) state.setBotStatus(data);
  }

  private async fetchMarket(): Promise<void> {
    const data = await api.getMarketIndicators();
    if (data) state.setMarketIndicators(data);
  }

  private async fetchUpnl(): Promise<void> {
    // Float PnL would come from status
  }

  private async fetchScalp(): Promise<void> {
    // Scalp stats endpoint
  }

  private async fetchLogs(): Promise<void> {
    const data = await api.getLogs();
    if (data) prependLogs(data);
  }

  private async fetchMLInfo(): Promise<void> {
    const data = await api.getMlInfo();
    if (data) state.setMlInfo(data);
  }

  private initEventHandlers(): void {
    const menuToggle = document.getElementById('menuToggle');
    const leftDrawer = document.getElementById('sidebarLeft');
    const drawerOverlay = document.getElementById('drawerOverlay');

    menuToggle?.addEventListener('click', () => {
      const isOpen = leftDrawer?.classList.toggle('open');
      drawerOverlay?.classList.toggle('active', isOpen || false);
      state.setLeftDrawerOpen(isOpen || false);
    });

    drawerOverlay?.addEventListener('click', () => {
      leftDrawer?.classList.remove('open');
      drawerOverlay.classList.remove('active');
      state.setLeftDrawerOpen(false);
      state.setRightDrawerOpen(false);
    });

    const rightToggle = document.getElementById('rightToggle');
    const rightDrawer = document.getElementById('sidebarRight');

    rightToggle?.addEventListener('click', () => {
      const isOpen = rightDrawer?.classList.toggle('open');
      drawerOverlay?.classList.toggle('active', isOpen || false);
      state.setRightDrawerOpen(isOpen || false);
    });

    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const tab = btn.getAttribute('data-tab') || '';
        this.switchTab(tab, btn);
      });
    });

    const chartTabPro = document.getElementById('chartTabPro');
    const chartTabFast = document.getElementById('chartTabFast');
    const tvChartWrap = document.getElementById('tvChartWrap');
    const candleChart = document.getElementById('candleChart');

    chartTabPro?.addEventListener('click', () => {
      this.switchChartTab('pro');
      tvChartWrap?.classList.remove('hidden');
      candleChart?.classList.add('hidden');
    });

    chartTabFast?.addEventListener('click', () => {
      this.switchChartTab('fast');
      tvChartWrap?.classList.add('hidden');
      candleChart?.classList.remove('hidden');
    });

    const logSearch = document.getElementById('logSearch') as HTMLInputElement;
    logSearch?.addEventListener('input', debounce((e) => {
      setLogFilter((e.target as HTMLInputElement).value);
    }, 200));

    document.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', () => this.handleControlAction(btn.getAttribute('data-action')!));
    });

    window.addEventListener('resize', debounce(() => this.resizeCharts(), 200));
    window.addEventListener('beforeunload', () => this.destroy());
  }

  private initCharts(): void {
    const chartIds = ['hChart', 'dChart', 'cumChart', 'confChart'];
    chartIds.forEach(id => {
      const canvas = document.getElementById(id) as HTMLCanvasElement;
      if (canvas) this.charts.set(id, canvas);
    });
  }

  private resizeCharts(): void {
    this.charts.forEach(chart => {
      if (chart && typeof chart.resize === 'function') {
        chart.resize();
      }
    });
  }

  private switchTab(tab: string, btn?: Element): void {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn?.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById(`tab-${tab}`)?.classList.add('active');
    state.setActiveRightTab(tab);
  }

  private switchChartTab(tab: 'pro' | 'fast'): void {
    const proBtn = document.getElementById('chartTabPro');
    const fastBtn = document.getElementById('chartTabFast');
    const tvWrap = document.getElementById('tvChartWrap');
    const candleWrap = document.getElementById('candleChart');

    if (tab === 'pro') {
      proBtn?.classList.add('active');
      fastBtn?.classList.remove('active');
      tvWrap?.classList.remove('hidden');
      candleWrap?.classList.add('hidden');
    } else {
      fastBtn?.classList.add('active');
      proBtn?.classList.remove('active');
      tvWrap?.classList.add('hidden');
      candleWrap?.classList.remove('hidden');
    }
    state.setChartTab(tab);
  }

  private openConfigModal(): void {
    const modal = document.getElementById('configModalOverlay');
    modal?.classList.add('active');
    state.setConfigModalOpen(true);

    const config = state.getState().botConfig;
    if (config) {
      (document.getElementById('cfgCapital') as HTMLInputElement).value = String(config.capitalUsd);
      (document.getElementById('cfgLeverage') as HTMLInputElement).value = String(config.leverage);
      (document.getElementById('cfgLevels') as HTMLInputElement).value = String(config.levels);
      (document.getElementById('cfgLongLevels') as HTMLInputElement).value = String(config.longLevels);
      (document.getElementById('cfgShortLevels') as HTMLInputElement).value = String(config.shortLevels);
      (document.getElementById('cfgSpacing') as HTMLInputElement).value = String(config.spacingPct * 100);
    }
  }

  private closeConfig(): void {
    document.getElementById('configModalOverlay')?.classList.remove('active');
    state.setConfigModalOpen(false);
  }

  private async handleControlAction(action: string): Promise<void> {
    try {
      let success = false;

      switch (action) {
        case 'stop':
          success = await api.sendCommand({ action: 'stop' });
          break;
        case 'force_ai':
          success = await api.sendCommand({ action: 'force_ai' });
          resetAiCountdown(state.getState().aiInterval);
          break;
        case 'reset_grid':
          success = await api.sendCommand({ action: 'reset_grid' });
          break;
        case 'export_pnl':
          await this.exportPnL();
          return;
        case 'open_config':
          this.openConfigModal();
          return;
        case 'apply_config':
          success = await this.applyConfig();
          break;
        case 'toggle_speed':
          const newMode = state.getState().speedMode === 'fast' ? 'normal' : 'fast';
          state.setSpeedMode(newMode);
          const speedBtn = document.getElementById('speedBtn');
          if (speedBtn) speedBtn.textContent = newMode === 'fast' ? '⚡ Rápido' : '🐢 Normal';
          return;
      }

      if (success) {
        showToast('Éxito', `Acción ${action} completada`, 'info');
      } else {
        showToast('Error', `Fallo en ${action}`, 'fill_neg');
      }
    } catch (error) {
      console.error('[App] Control action failed:', error);
      showToast('Error', 'Error de conexión', 'fill_neg');
    }
  }

  private async applyConfig(): Promise<boolean> {
    const updates: Record<string, string | number> = {
      capital_usd: parseFloat((document.getElementById('cfgCapital') as HTMLInputElement).value),
      leverage: parseInt((document.getElementById('cfgLeverage') as HTMLInputElement).value, 10),
      levels: parseInt((document.getElementById('cfgLevels') as HTMLInputElement).value, 10),
      long_levels: parseInt((document.getElementById('cfgLongLevels') as HTMLInputElement).value, 10),
      short_levels: parseInt((document.getElementById('cfgShortLevels') as HTMLInputElement).value, 10),
      spacing_pct: parseFloat((document.getElementById('cfgSpacing') as HTMLInputElement).value) / 100,
    };

    const success = await api.updateConfig(updates);
    if (success) {
      this.closeConfig();
    }
    return success;
  }

  private async exportPnL(): Promise<void> {
    const exportToken = state.getState().exportToken;
    const csv = await api.exportPnL(exportToken);
    if (csv) {
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `pnl_diario_ethusdt_${new Date().toISOString().slice(0, 10)}.csv`;
      link.click();
      showToast('Exportado', 'CSV descargado correctamente', 'info');
    } else {
      showToast('Error', 'No se pudo exportar', 'fill_neg');
    }
  }

  private updateTickerUI(ticker: Ticker): void {
    this.updateElement('priceLive', formatPrice(ticker.lastPrice));
    this.updateElement('priceChg', formatPct(ticker.price24hPcnt * 100), { className: `price-chg ${ticker.price24hPcnt >= 0 ? 'up' : 'dn'}` });
    this.updateElement('priceHL', `H: ${formatPrice(ticker.highPrice24h)} · L: ${formatPrice(ticker.lowPrice24h)} · Vol: ${formatNumber(ticker.volume24h)}`);
    this.updateElement('bidPx', formatPrice(ticker.bidPrice));
    this.updateElement('askPx', formatPrice(ticker.askPrice));
    this.updateElement('spreadVal', formatPrice(ticker.spread, 4));
    this.updateElement('tbFunding', formatPct(ticker.fundingRate * 100, 4));
    this.updateElement('tbMark', formatPrice(ticker.markPrice));
  }

  private updateBotStatus(status: BotStatus): void {
    if (!status) return;

    state.markUpdate();

    this.updateElement('kUpt', status.uptime);
    this.updateElement('uptTxt', status.uptime);
    this.updateElement('wUpt', status.uptime);

    const mode = status.mode || 'NORMAL';
    this.updateElement('modeBadge', mode);
    const modeBadge = document.getElementById('modeBadge');
    if (modeBadge) modeBadge.className = `mode-badge m-${mode}`;

    const pair = status.pairs?.ETHUSDT;
    if (pair) {
      state.setPairStatus(pair);
      if (pair.orders) state.setOrders(pair.orders);
    }

    if (status.pnl_hourly) state.setPnLHourly(status.pnl_hourly);
    if (status.pnl_daily) state.setPnLDaily(status.pnl_daily);
  }

  private updatePairUI(pair: PairStatus): void {
    renderAiGauge(document.getElementById('heroCol')!, {
      direction: pair.direction as any,
      confidence: pair.confidence,
      reason: pair.aiReason,
      mlAccuracy: pair.ml_accuracy || 0,
    });

    this.updateElement('strategyName', pair.direction);
    this.updateElement('strategyMode', state.getState().botStatus?.mode || 'NORMAL');
    const strategyModeEl = document.getElementById('strategyMode');
    if (strategyModeEl) strategyModeEl.className = `mode-badge m-${state.getState().botStatus?.mode || 'NORMAL'}`;
    this.updateElement('strategyBot', pair.grid_built ? 'ON' : 'OFF');
    this.updateElement('strategyGrid', `${pair.open_entries}E ${pair.open_exits}S`);
    this.updateElement('strategyCycle', String(pair.cycle_n));
    this.updateElement('strategyAiTs', pair.last_ai_check || '--');
    this.updateElement('strategyReason', pair.ai_reason || '--');

    this.updateElement('cNiv', String(pair.levels));
    this.updateElement('cLS', `${pair.long_levels} / ${pair.short_levels}`);
    this.updateElement('cSpc', `${(pair.spacing_pct * 100).toFixed(4)}%`);
    this.updateElement('cEnt', String(pair.open_entries));
    this.updateElement('cSal', String(pair.open_exits));
    this.updateElement('cMlAcc', pair.ml_accuracy > 0 ? `${(pair.ml_accuracy * 100).toFixed(1)}%` : '--%');
    this.updateElement('stRecov2', pair.recovery_active ? 'Sí' : 'No');

    const gridDot = document.getElementById('gridDot');
    const gridTxt = document.getElementById('gridStatusTxt');
    const cycleN = document.getElementById('cycleN');
    if (gridDot) gridDot.className = `gs-dot ${pair.grid_built ? 'on' : 'off'}`;
    if (gridTxt) gridTxt.textContent = `Grid ${pair.grid_built ? 'ON' : 'OFF'} · ${pair.open_entries}E ${pair.open_exits}S`;
    if (cycleN) cycleN.textContent = String(pair.cycle_n);

    const pnlToday = pair.pnl_today;
    const pnlTotal = pair.pnl_total || 0;
    const capital = state.getState().capitalCfg;

    this.updateElement('kPnlH', formatMoney(pnlToday, 4));
    this.updateElement('kPnlHP', `${capital > 0 ? ((pnlToday / capital) * 100).toFixed(2) : '0.00'}% capital`);
    this.updateElement('kPnlT', formatMoney(pnlTotal, 4));
    this.updateElement('kFillsT', `${pair.fills_per_hour || 0} fills/hora`);
    this.updateElement('kWin', `${pair.avg_pnl_fill > 0 ? '+' : ''}${pair.avg_pnl_fill.toFixed(2)} avg`);
    this.updateElement('kProj', formatMoney(pair.pnl_proj_30d || 0, 2));

    const balance = state.getState().botStatus?.real_balance || 0;
    this.updateElement('wBalance', formatPrice(balance));
    this.updateElement('wMarginUsed', formatPrice(capital));
    this.updateElement('wMarginFree', formatPrice(Math.max(0, balance - capital)));
    this.updateElement('wUpnl', formatMoney(pair.total_upnl || 0, 2));
    this.updateElement('wRoiD', formatPct(capital > 0 ? (pnlToday / capital) * 100 : 0, 2));
    this.updateElement('wRoiT', formatPct(capital > 0 ? (pnlTotal / capital) * 100 : 0, 2));
    this.updateElement('wProj', formatMoney(pair.pnl_proj_30d || 0, 2));
    this.updateElement('wFees', '$0.00');

    this.updateElement('stOpen', String(pair.open_entries + pair.open_exits));
    this.updateElement('stFills', String(pair.fills_per_hour || 0));
    this.updateElement('stFillsH', String(pair.fills_per_hour || 0));
    this.updateElement('stPeak', formatMoney(pair.peak_pnl || 0, 4));
    this.updateElement('stRecov', pair.recovery_active ? 'Sí' : 'No');
    this.updateElement('stWr', `${pair.win_rate || 0}%`);
    this.updateElement('stFillH', String(pair.fills_per_hour || 0));
    this.updateElement('stPnl1h', formatMoney(pair.pnl_1h || 0, 4));

    this.updateElement('stPx', formatPrice(pair.price));
    this.updateElement('stChg', formatPct(0, 2));
    this.updateElement('stH', formatPrice(0));
    this.updateElement('stL', formatPrice(0));
    this.updateElement('stVol', formatNumber(0));
    this.updateElement('stSpr', formatPrice(0, 4));
    this.updateElement('stRsi', String(pair.rsi || 'N/A'));
    this.updateElement('stMacd', String(pair.macd || 'N/A'));
    this.updateElement('stFund', String(pair.funding_rate || 'N/A'));
    this.updateElement('stOi', String(pair.open_interest || 'N/A'));
    this.updateElement('stMark', formatPrice(pair.mark_price || 0));
    this.updateElement('stAdx', String(pair.adx || 'N/A'));
  }

  private updateRecentFills(fills: Fill[]): void {
    const activeTab = state.getState().activeRightTab;
    if (activeTab === 'fills') {
      renderFillsTable(document.getElementById('tab-fills')!, 1);
    }

    const lastFills = state.getState().recentFills;
    fills.forEach(fill => {
      const id = `${fill.execTime}_${fill.side}_${fill.closedPnl}`;
      if (!lastFills.some(f => `${f.execTime}_${f.side}_${f.closedPnl}` === id) && fill.execType === 'Trade') {
        const pnl = parseFloat(fill.closedPnl?.toString() || '0');
        showToast('Fill completado', `${fill.side} EXIT · PnL: ${pnl >= 0 ? '+' : ''}${pnl.toFixed(4)} USDT`,
          pnl >= 0 ? 'fill_pos' : 'fill_neg');
      }
    });
  }

  private updatePositions(positions: Position[], totalUpnl: number): void {
    const pb = document.getElementById('posBody');
    if (pb) {
      if (positions.length > 0) {
        pb.innerHTML = positions.map(p => {
          const amt = parseFloat(p.size.toString());
          const side = amt > 0 ? 'BUY' : 'SELL';
          return `
            <tr>
              <td><span class="badge ${amt > 0 ? 'b-buy' : 'b-sell'}">${side}</span></td>
              <td>${formatQty(Math.abs(amt))}</td>
              <td>${formatPrice(p.entryPrice)}</td>
              <td>${formatMoney(p.unRealizedProfit || 0, 4)}</td>
              <td style="color:var(--red)">${formatPrice(p.liquidationPrice)}</td>
            </tr>
          `;
        }).join('');
      } else {
        pb.innerHTML = '<tr><td colspan="5" class="no-data">Sin posición abierta</td></tr>';
      }
    }

    const chip = document.getElementById('upnlChip');
    const chipVal = document.getElementById('upnlChipVal');
    const box = document.getElementById('upnlBox');
    const boxVal = document.getElementById('upnlVal');

    if (chip && chipVal) {
      if (positions.length > 0 || Math.abs(totalUpnl) > 0.0001) {
        chip.style.display = 'flex';
        chipVal.innerHTML = formatMoney(totalUpnl, 4);
        (chipVal as HTMLElement).style.borderColor = totalUpnl >= 0 ? 'rgba(0,201,122,.4)' : 'rgba(240,60,82,.4)';
      } else {
        chip.style.display = 'none';
      }
    }

    if (box && boxVal) {
      if (positions.length > 0) {
        box.style.display = 'flex';
        boxVal.innerHTML = formatMoney(totalUpnl, 4);
      } else {
        box.style.display = 'none';
      }
    }
  }

  private updatePnLCharts(data: PnLData): void {
    if (data.hourly?.length) {
      createHourlyPnLChart(document.getElementById('hChart')!.parentElement!, data.hourly);
    }
    if (data.daily?.length) {
      createDailyPnLChart(document.getElementById('dChart')!.parentElement!, data.daily);
    }
    if (data.cumulative?.length) {
      createCumulativePnLChart(document.getElementById('cumChart')!.parentElement!, data.cumulative);
    }
  }

  private updateMlFeatures(features: Record<string, number>): void {
    if (state.getState().activeRightTab === 'ml') {
      const container = document.getElementById('mlFeatBars');
      if (container) {
        const maxVal = Math.max(...Object.values(features).map(Math.abs));
        container.innerHTML = Object.entries(features)
          .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]))
          .map(([name, value]) => {
            const pct = maxVal > 0 ? (Math.abs(value) / maxVal) * 100 : 0;
            const isPositive = value >= 0;
            return `
              <div class="ml-feat-row">
                <span class="ml-feat-name">${name}</span>
                <div class="ml-feat-bar-bg">
                  <div class="ml-feat-bar" style="width:${pct.toFixed(1)}%; background:${isPositive ? 'var(--green)' : 'var(--red)'}"></div>
                </div>
                <span class="ml-feat-val">${value.toFixed(3)}</span>
              </div>
            `;
          }).join('');
      }
    }
  }

  private requestNotificationPermission(): void {
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }
  }

  private updateElement(id: string, text: string, options?: { className?: string }): void {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = text;
      if (options?.className) {
        el.className = options.className;
      }
    }
  }

  destroy(): void {
    this.pollingTimers.forEach(timer => clearInterval(timer));
    this.pollingTimers.clear();

    if (this.ws) {
      this.ws.disconnect();
      this.ws = null;
    }

    this.unsubscribers.forEach(unsub => unsub());
    this.unsubscribers.length = 0;

    this.charts.forEach(chart => {
      if (chart && typeof chart.destroy === 'function') {
        chart.destroy();
      }
    });
    this.charts.clear();

    stopAiCountdown();
  }
}

function debounce<T extends (...args: any[]) => any>(fn: T, ms: number): (...args: Parameters<T>) => void {
  let timeoutId: ReturnType<typeof setTimeout>;
  return (...args: Parameters<T>) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), ms);
  };
}

// Initialize app when DOM is ready
let app: DashboardApp | null = null;

document.addEventListener('DOMContentLoaded', async () => {
  app = new DashboardApp();
  await app.init();
});

export { DashboardApp };