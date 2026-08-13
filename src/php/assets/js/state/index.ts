// Centralized state management for the dashboard

import type {
  Ticker,
  BotStatus,
  PairStatus,
  Order,
  Fill,
  Position,
  PnLData,
  MarketIndicators,
  MlInfo,
  LogEntry,
  BotConfig,
} from '../types';

interface DashboardState {
  // Connection
  wsConnected: boolean;
  pollingActive: boolean;
  lastUpdate: number | null;
  isStale: boolean;

  // Market data
  ticker: Ticker | null;
  marketIndicators: MarketIndicators | null;
  candles: Array<{ time: number; open: number; high: number; low: number; close: number; volume: number }>;

  // Bot status
  botStatus: BotStatus | null;
  pairStatus: PairStatus | null;
  botConfig: BotConfig | null;

  // Orders & Fills
  orders: Order[];
  recentFills: Fill[];
  fillsHistory: Fill[];
  fillsOffset: number;
  fillsTotal: number;

  // Positions
  positions: Position[];
  totalUpnl: number;

  // PnL
  pnlData: PnLData | null;

  // ML
  mlInfo: MlInfo | null;
  mlFeatures: Record<string, number>;

  // Logs
  logs: LogEntry[];
  logFilter: string;
  logPaused: boolean;

  // UI State
  activeRightTab: string;
  leftDrawerOpen: boolean;
  rightDrawerOpen: boolean;
  configModalOpen: boolean;
  speedMode: 'fast' | 'normal';
  chartTab: 'pro' | 'fast';

  // Constants from PHP
  capitalCfg: number;
  aiInterval: number;
  ctrlToken: string;
  exportToken: string;
}

const initialState: DashboardState = {
  wsConnected: false,
  pollingActive: false,
  lastUpdate: null,
  isStale: false,

  ticker: null,
  marketIndicators: null,
  candles: [],

  botStatus: null,
  pairStatus: null,
  botConfig: null,

  orders: [],
  recentFills: [],
  fillsHistory: [],
  fillsOffset: 0,
  fillsTotal: 0,

  positions: [],
  totalUpnl: 0,

  pnlData: null,

  mlInfo: null,
  mlFeatures: {},

  logs: [],
  logFilter: '',
  logPaused: false,

  activeRightTab: 'stats',
  leftDrawerOpen: false,
  rightDrawerOpen: false,
  configModalOpen: false,
  speedMode: 'fast',
  chartTab: 'pro',

  capitalCfg: 0,
  aiInterval: 120,
  ctrlToken: '',
  exportToken: '',
};

type Listener = (state: DashboardState) => void;

class StateManager {
  private state: DashboardState = { ...initialState };
  private listeners: Set<Listener> = new Set();

  getState(): Readonly<DashboardState> {
    return this.state;
  }

  subscribe(listener: Listener): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  private notify(): void {
    this.listeners.forEach(listener => listener(this.state));
  }

  // Connection
  setWsConnected(connected: boolean): void {
    this.state.wsConnected = connected;
    this.notify();
  }

  setPollingActive(active: boolean): void {
    this.state.pollingActive = active;
    this.notify();
  }

  markUpdate(): void {
    this.state.lastUpdate = Date.now();
    this.state.isStale = false;
    document.body.classList.remove('stale');
    this.notify();
  }

  setStale(stale: boolean): void {
    this.state.isStale = stale;
    if (stale) {
      document.body.classList.add('stale');
    } else {
      document.body.classList.remove('stale');
    }
    this.notify();
  }

  // Market data
  setTicker(ticker: Ticker): void {
    this.state.ticker = ticker;
    this.notify();
  }

  setMarketIndicators(indicators: MarketIndicators): void {
    this.state.marketIndicators = indicators;
    this.notify();
  }

  setCandles(candles: DashboardState['candles']): void {
    this.state.candles = candles;
    this.notify();
  }

  // Bot status
  setBotStatus(status: BotStatus): void {
    this.state.botStatus = status;
    this.notify();
  }

  setPairStatus(pair: PairStatus): void {
    this.state.pairStatus = pair;
    this.notify();
  }

  setBotConfig(config: BotConfig): void {
    this.state.botConfig = config;
    this.notify();
  }

  // Orders
  setOrders(orders: Order[]): void {
    this.state.orders = orders;
    this.notify();
  }

  // Fills
  setRecentFills(fills: Fill[]): void {
    this.state.recentFills = fills;
    this.notify();
  }

  setFillsHistory(fills: Fill[], offset: number, total: number): void {
    this.state.fillsHistory = fills;
    this.state.fillsOffset = offset;
    this.state.fillsTotal = total;
    this.notify();
  }

  prependFillsHistory(newFills: Fill[]): void {
    this.state.fillsHistory = [...newFills, ...this.state.fillsHistory].slice(0, 1000);
    this.notify();
  }

  // Positions
  setPositions(positions: Position[], totalUpnl: number): void {
    this.state.positions = positions;
    this.state.totalUpnl = totalUpnl;
    this.notify();
  }

  // PnL
  setPnLData(data: PnLData): void {
    this.state.pnlData = data;
    this.notify();
  }

  setPnLHourly(hourly: PnLData['hourly']): void {
    if (this.state.pnlData) {
      this.state.pnlData.hourly = hourly;
    }
    this.notify();
  }

  setPnLDaily(daily: PnLData['daily']): void {
    if (this.state.pnlData) {
      this.state.pnlData.daily = daily;
    }
    this.notify();
  }

  setPnLCumulative(cumulative: PnLData['cumulative']): void {
    if (this.state.pnlData) {
      this.state.pnlData.cumulative = cumulative;
    }
    this.notify();
  }

  // ML
  setMlInfo(info: MlInfo): void {
    this.state.mlInfo = info;
    this.notify();
  }

  setMlFeatures(features: Record<string, number>): void {
    this.state.mlFeatures = features;
    this.notify();
  }

  // Logs
  setLogs(logs: LogEntry[]): void {
    this.state.logs = logs;
    this.notify();
  }

  prependLogs(newLogs: LogEntry[]): void {
    // Avoid duplicates
    const existing = new Set(this.state.logs.slice(-10).map(l => `${l.time}|${l.level}|${l.message}`));
    const unique = newLogs.filter(l => !existing.has(`${l.time}|${l.level}|${l.message}`));
    this.state.logs = [...this.state.logs, ...unique].slice(-500);
    this.notify();
  }

  setLogFilter(filter: string): void {
    this.state.logFilter = filter;
    this.notify();
  }

  setLogPaused(paused: boolean): void {
    this.state.logPaused = paused;
    this.notify();
  }

  // UI State
  setActiveRightTab(tab: string): void {
    this.state.activeRightTab = tab;
    this.notify();
  }

  setLeftDrawerOpen(open: boolean): void {
    this.state.leftDrawerOpen = open;
    this.notify();
  }

  setRightDrawerOpen(open: boolean): void {
    this.state.rightDrawerOpen = open;
    this.notify();
  }

  setConfigModalOpen(open: boolean): void {
    this.state.configModalOpen = open;
    this.notify();
  }

  setSpeedMode(mode: 'fast' | 'normal'): void {
    this.state.speedMode = mode;
    this.notify();
  }

  setChartTab(tab: 'pro' | 'fast'): void {
    this.state.chartTab = tab;
    this.notify();
  }

  // Constants
  setConstants(constants: { capitalCfg: number; aiInterval: number; ctrlToken: string; exportToken: string }): void {
    this.state.capitalCfg = constants.capitalCfg;
    this.state.aiInterval = constants.aiInterval;
    this.state.ctrlToken = constants.ctrlToken;
    this.state.exportToken = constants.exportToken;
    this.notify();
  }

  // Reset
  reset(): void {
    this.state = { ...initialState };
    this.notify();
  }
}

export const state = new StateManager();
export default state;