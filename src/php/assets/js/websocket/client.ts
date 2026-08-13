// WebSocket client for real-time updates

import type { WebSocketMessage, Ticker, BotStatus, PairStatus, Order, Fill, Position, PnLData, LogEntry, MarketIndicators } from '../types';

type MessageHandler = (data: any) => void;

interface WSCallbacks {
  onTicker?: (data: Ticker) => void;
  onStatus?: (data: BotStatus) => void;
  onPair?: (data: PairStatus) => void;
  onOrders?: (data: Order[]) => void;
  onFills?: (data: Fill[]) => void;
  onPositions?: (data: Position[]) => void;
  onPnL?: (data: PnLData) => void;
  onPnLHourly?: (data: PnLData['hourly']) => void;
  onPnLCumulative?: (data: PnLData['cumulative']) => void;
  onConfidence?: (data: LogEntry[]) => void;
  onLogs?: (data: LogEntry[]) => void;
  onMarketIndicators?: (data: MarketIndicators) => void;
  onMlFeatures?: (data: Record<string, number>) => void;
  onConnect?: () => void;
  onDisconnect?: () => void;
  onError?: (error: Event) => void;
}

export class WebSocketClient {
  private ws: WebSocket | null = null;
  private url: string;
  private token: string;
  private reconnectDelay = 1000;
  private maxReconnectDelay = 15000;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setTimeout> | null = null;
  private staleTimer: ReturnType<typeof setTimeout> | null = null;
  private callbacks: WSCallbacks = {};
  private isConnecting = false;
  private shouldReconnect = true;

  constructor(token: string) {
    this.token = token;
    const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    this.url = `${proto}//${window.location.host}/ws/?token=${token}`;
  }

  setCallbacks(callbacks: WSCallbacks): void {
    this.callbacks = { ...this.callbacks, ...callbacks };
  }

  connect(): void {
    if (this.ws?.readyState === WebSocket.OPEN || this.isConnecting) {
      return;
    }

    this.isConnecting = true;
    this.shouldReconnect = true;

    try {
      this.ws = new WebSocket(this.url);
      this.setupEventHandlers();
    } catch (error) {
      console.error('[WS] Connection failed:', error);
      this.isConnecting = false;
      this.scheduleReconnect();
    }
  }

  private setupEventHandlers(): void {
    if (!this.ws) return;

    this.ws.onopen = () => {
      console.log('[WS] Connected');
      this.isConnecting = false;
      this.reconnectDelay = 1000;
      this.callbacks.onConnect?.();
      this.startHeartbeat();
      this.startStaleTimer();
    };

    this.ws.onmessage = (event) => {
      this.handleMessage(event.data);
    };

    this.ws.onerror = (error) => {
      console.warn('[WS] Error:', error);
      this.callbacks.onError?.(error);
    };

    this.ws.onclose = () => {
      console.log('[WS] Disconnected');
      this.cleanup();
      this.callbacks.onDisconnect?.();
      if (this.shouldReconnect) {
        this.scheduleReconnect();
      }
    };
  }

  private handleMessage(data: string): void {
    try {
      const message: WebSocketMessage = JSON.parse(data);
      this.resetStaleTimer();

      // Heartbeat
      if (message.type === 'heartbeat') {
        return;
      }

      // Route to appropriate callback
      switch (message.type) {
        case 'ticker':
          this.callbacks.onTicker?.(message.data);
          break;
        case 'status':
          this.callbacks.onStatus?.(message.data);
          break;
        case 'pair':
          this.callbacks.onPair?.(message.data);
          break;
        case 'orders':
          this.callbacks.onOrders?.(message.data);
          break;
        case 'fills':
          this.callbacks.onFills?.(message.data);
          break;
        case 'pos':
          this.callbacks.onPositions?.(message.data);
          break;
        case 'pnl':
          this.callbacks.onPnL?.(message.data);
          break;
        case 'pnl_hourly':
          this.callbacks.onPnLHourly?.(message.data);
          break;
        case 'pnl_cumulative':
          this.callbacks.onPnLCumulative?.(message.data);
          break;
        case 'confidence':
          this.callbacks.onConfidence?.(message.data);
          break;
        case 'logs':
          this.callbacks.onLogs?.(message.data);
          break;
        case 'market':
          this.callbacks.onMarketIndicators?.(message.data);
          break;
        case 'ml_features':
          this.callbacks.onMlFeatures?.(message.data);
          break;
        default:
          console.debug('[WS] Unknown message type:', message.type);
      }
    } catch (error) {
      console.warn('[WS] Parse error:', error);
    }
  }

  private startHeartbeat(): void {
    this.heartbeatTimer = setInterval(() => {
      if (this.ws?.readyState === WebSocket.OPEN) {
        this.ws.send(JSON.stringify({ type: 'ping' }));
      }
    }, 30000);
  }

  private startStaleTimer(): void {
    this.staleTimer = setTimeout(() => {
      document.body.classList.add('stale');
    }, 10000);
  }

  private resetStaleTimer(): void {
    document.body.classList.remove('stale');
    if (this.staleTimer) {
      clearTimeout(this.staleTimer);
    }
    this.startStaleTimer();
  }

  private scheduleReconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
    }

    const delay = this.reconnectDelay;
    this.reconnectDelay = Math.min(this.reconnectDelay * 2, this.maxReconnectDelay);

    this.reconnectTimer = setTimeout(() => {
      this.connect();
    }, delay);
  }

  private cleanup(): void {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
    if (this.staleTimer) {
      clearTimeout(this.staleTimer);
      this.staleTimer = null;
    }
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    this.ws = null;
  }

  disconnect(): void {
    this.shouldReconnect = false;
    this.cleanup();
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
  }

  send(data: object): boolean {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(data));
      return true;
    }
    return false;
  }

  get readyState(): number {
    return this.ws?.readyState ?? WebSocket.CLOSED;
  }

  isConnected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN;
  }
}

// Singleton instance
let wsInstance: WebSocketClient | null = null;

export function getWebSocketClient(token: string): WebSocketClient {
  if (!wsInstance) {
    wsInstance = new WebSocketClient(token);
  }
  return wsInstance;
}

export function destroyWebSocketClient(): void {
  if (wsInstance) {
    wsInstance.disconnect();
    wsInstance = null;
  }
}