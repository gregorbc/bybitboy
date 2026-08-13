// Core domain types for the Grid Bot dashboard

export interface Ticker {
  symbol: string;
  lastPrice: number;
  price24hPcnt: number;
  highPrice24h: number;
  lowPrice24h: number;
  volume24h: number;
  turnover24h: number;
  bidPrice: number;
  askPrice: number;
  spread: number;
  fundingRate: number;
  nextFundingTime: number;
  markPrice: number;
  indexPrice: number;
  openInterest: number;
  openInterestValue: number;
  timestamp: number;
}

export interface Position {
  symbol: string;
  side: 'Buy' | 'Sell';
  size: number;
  entryPrice: number;
  markPrice: number;
  unRealizedProfit: number;
  liquidationPrice: number;
  leverage: number;
  autoAddMargin: boolean;
}

export interface Order {
  orderId: string;
  orderLinkId: string;
  symbol: string;
  side: 'Buy' | 'Sell';
  orderType: 'Limit' | 'Market';
  price: number;
  qty: number;
  leavesQty: number;
  cumExecQty: number;
  cumExecValue: number;
  status: 'New' | 'PartiallyFilled' | 'Filled' | 'Cancelled' | 'Rejected' | 'Expired';
  timeInForce: 'GTC' | 'IOC' | 'FOK';
  isLeverage: 0 | 1;
  createTime: string;
  updateTime: string;
  gridLevel?: number;
  gridRole?: 'ENTRY' | 'EXIT';
  linkedOrderId?: string;
}

export interface Fill {
  execId: string;
  orderId: string;
  orderLinkId: string;
  symbol: string;
  side: 'Buy' | 'Sell';
  price: number;
  qty: number;
  execTime: string;
  execType: 'Trade' | 'AdlTrade' | 'Funding' | 'BustTrade';
  execFee: number;
  execValue: number;
  closedPnl: number;
}

export interface BotConfig {
  symbol: string;
  direction: 'UP' | 'DOWN' | 'SIDEWAYS' | 'NEUTRAL';
  confidence: number;
  aiReason: string;
  lastAiCheck: string | null;
  levels: number;
  spacingPct: number;
  longLevels: number;
  shortLevels: number;
  qtyPerLevel: number;
  pp: number;
  qp: number;
  mode: 'NORMAL' | 'RECOVERY' | 'grid-off';
  recoveryActive: boolean;
  mlAccuracy: number;
  capitalUsd: number;
  leverage: number;
}

export interface BotStatus {
  running: boolean;
  uptime: string;
  mode: string;
  aiEngine: string;
  cycleN: number;
  lastNavSync: number;
  pairs: {
    [symbol: string]: PairStatus;
  };
  realBalance: number;
  mlAccuracy: number;
}

export interface PairStatus {
  price: number;
  direction: string;
  confidence: number;
  aiReason: string;
  lastAiCheck: string | null;
  levels: number;
  longLevels: number;
  shortLevels: number;
  spacingPct: number;
  leverage: number;
  pnlToday: number;
  peakPnl: number;
  recoveryActive: boolean;
  gridBuilt: boolean;
  fillsPerHour: number;
  pnl1h: number;
  avgPnlFill: number;
  cycleN: number;
  lastNavSync: number;
  realPositions: Position[];
  atrPredicted: number | null;
  vlUsed: boolean;
  vlDirection: string | null;
  vlConfidence: number | null;
  openEntries: number;
  openExits: number;
}

export interface MarketIndicators {
  rsi: number;
  rsiSignal: 'oversold' | 'neutral' | 'overbought';
  macd: number;
  macdSignal: 'bullish' | 'bearish' | 'neutral';
  adx: number;
  adxSignal: 'strong' | 'weak' | 'neutral';
  atrPct: number;
  volRatio: number;
  bbPct: number;
  bbRange: { lower: number; upper: number };
  fundingRate: number;
  nextFundingTime: number;
  openInterest: number;
  oiValue: number;
  ema9: number;
  ema21: number;
  ema50: number;
}

export interface PnLData {
  hourly: Array<{ h: string; p: number; c: number }>;
  daily: Array<{ d: string; p: number; c: number }>;
  cumulative: Array<{ d: string; p: number }>;
  total: number;
  today: number;
  peak: number;
  winRate: number;
  avgPnlPerFill: number;
  fillsToday: number;
  fillsTotal: number;
}

export interface MlInfo {
  accuracy: number;
  features: number;
  symbol: string;
  updatedAt: string;
  importances: Record<string, number>;
}

export interface LogEntry {
  time: string;
  level: 'INFO' | 'WARN' | 'ERROR' | 'DEBUG';
  message: string;
}

export interface WebSocketMessage {
  type: 'ticker' | 'status' | 'pair' | 'orders' | 'fills' | 'pos' | 'pnl' | 'pnl_hourly' | 'pnl_cumulative' | 'confidence' | 'logs' | 'ml_features' | 'heartbeat';
  data: any;
}

export interface ControlCommand {
  action?: 'stop' | 'force_ai' | 'reset_grid' | 'reset_pair';
  config_update?: Partial<BotConfig>;
}

export interface GridAjaxResponse {
  ok: boolean;
  data?: any;
  error?: string;
}

// Chart data types
export interface ChartDataset {
  label: string;
  data: number[];
  borderColor: string;
  backgroundColor?: string;
  fill?: boolean;
  tension?: number;
  pointRadius?: number;
  pointBackgroundColor?: string;
}

export interface ChartData {
  labels: string[];
  datasets: ChartDataset[];
}

export interface CandleData {
  time: number;
  open: number;
  high: number;
  low: number;
  close: number;
}

export interface OrderLadderRow {
  level: number;
  side: 'BUY' | 'SELL';
  role: 'ENTRY' | 'EXIT';
  price: number;
  qty: number;
  isCurrentPrice: boolean;
}