// API client for communicating with PHP backend

import type {
  Ticker,
  BotStatus,
  MarketIndicators,
  PnLData,
  MlInfo,
  LogEntry,
  GridAjaxResponse,
  ControlCommand,
} from '@/types';

const API_BASE = '/src/php/grid_ajax.php';

class ApiClient {
  private baseUrl: string;
  private defaultHeaders: HeadersInit;

  constructor(baseUrl: string = API_BASE) {
    this.baseUrl = baseUrl;
    this.defaultHeaders = {
      'Content-Type': 'application/x-www-form-urlencoded',
    };
  }

  private async request<T>(action: string, params: Record<string, string> = {}): Promise<T | null> {
    const url = new URL(this.baseUrl, window.location.origin);
    url.searchParams.set('action', action);

    Object.entries(params).forEach(([key, value]) => {
      url.searchParams.set(key, value);
    });

    try {
      const response = await fetch(url.toString(), {
        method: 'GET',
        headers: this.defaultHeaders,
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const data: GridAjaxResponse = await response.json();
      if (!data.ok) {
        throw new Error(data.error || 'API error');
      }

      return data.data as T;
    } catch (error) {
      console.error(`[API] ${action} failed:`, error);
      return null;
    }
  }

  // Ticker / Market data
  async getTicker(): Promise<Ticker | null> {
    return this.request<Ticker>('ticker');
  }

  async getMarketIndicators(): Promise<MarketIndicators | null> {
    return this.request<MarketIndicators>('market');
  }

  // Bot status
  async getStatus(): Promise<BotStatus | null> {
    return this.request<BotStatus>('status');
  }

  // PnL data
  async getPnL(): Promise<PnLData | null> {
    return this.request<PnLData>('pnl');
  }

  // ML info
  async getMlInfo(): Promise<MlInfo | null> {
    return this.request<MlInfo>('ml_info');
  }

  // Logs
  async getLogs(limit: number = 500): Promise<LogEntry[] | null> {
    return this.request<LogEntry[]>('logs', { limit: String(limit) });
  }

  // Control commands
  async sendCommand(command: ControlCommand): Promise<boolean> {
    const result = await this.request<{ ok: boolean }>('control', {
      command: JSON.stringify(command),
    });
    return result?.ok ?? false;
  }

  // Configuration update
  async updateConfig(config: Record<string, string | number>): Promise<boolean> {
    // Convert all values to strings
    const stringConfig: Record<string, string> = {};
    Object.entries(config).forEach(([key, value]) => {
      stringConfig[key] = String(value);
    });
    const result = await this.request<{ ok: boolean }>('update_config', stringConfig);
    return result?.ok ?? false;
  }

  // Export PnL CSV
  async exportPnL(token: string): Promise<string | null> {
    const url = new URL('/src/php/index.php', window.location.origin);
    url.searchParams.set('export_pnl', '1');
    url.searchParams.set('token', token);

    try {
      const response = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      return await response.text();
    } catch (error) {
      console.error('[API] Export PnL failed:', error);
      return null;
    }
  }
}

export const api = new ApiClient();
export default api;