// Base chart component following dataviz methodology

import type { ChartData, ChartDataset } from '../types';

export interface ChartOptions {
  container: HTMLElement;
  data: ChartData;
  type: 'line' | 'bar' | 'area';
  options?: Partial<ChartConfiguration>;
  onRender?: (chart: Chart) => void;
}

export interface ChartConfiguration {
  responsive: boolean;
  maintainAspectRatio: boolean;
  interaction: {
    intersect: boolean;
    mode: 'index' | 'nearest' | 'dataset' | 'x' | 'y';
  };
  plugins: {
    legend: {
      display: boolean;
      position: 'top' | 'bottom' | 'left' | 'right';
      labels: {
        usePointStyle: boolean;
        padding: number;
        font: { size: number; family: string };
        color: string;
      };
    };
    tooltip: {
      enabled: boolean;
      mode: 'index' | 'nearest' | 'dataset' | 'x' | 'y';
      intersect: boolean;
      backgroundColor: string;
      titleColor: string;
      bodyColor: string;
      borderColor: string;
      borderWidth: number;
      padding: number;
      displayColors: boolean;
      callbacks: {
        label: (context: any) => string;
      };
    };
  };
  scales: {
    x: {
      grid: { display: boolean; color: string };
      ticks: { color: string; font: { size: number; family: string }; maxRotation: number; autoSkip: boolean };
    };
    y: {
      grid: { display: boolean; color: string };
      ticks: { color: string; font: { size: number; family: string }; callback: (value: number) => string };
      beginAtZero?: boolean;
    };
  };
  elements: {
    line: { tension: number; borderWidth: number; borderCapStyle: 'round' | 'butt' | 'square'; borderJoinStyle: 'round' | 'bevel' | 'miter' };
    point: { radius: number; hoverRadius: number; borderWidth: number };
    bar: { borderRadius: number; borderSkipped: boolean };
  };
}

const DEFAULT_CONFIG: ChartConfiguration = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    intersect: false,
    mode: 'index',
  },
  plugins: {
    legend: {
      display: true,
      position: 'top',
      labels: {
        usePointStyle: true,
        padding: 12,
        font: { size: 10, family: 'var(--sans)' },
        color: 'var(--muted)',
      },
    },
    tooltip: {
      enabled: true,
      mode: 'index',
      intersect: false,
      backgroundColor: 'var(--bg3)',
      titleColor: 'var(--text)',
      bodyColor: 'var(--dim)',
      borderColor: 'var(--border)',
      borderWidth: 1,
      padding: 10,
      displayColors: true,
      callbacks: {
        label: (context: any) => {
          const value = context.parsed.y;
          const prefix = value >= 0 ? '+' : '';
          return `${context.dataset.label}: ${prefix}${value.toFixed(4)}`;
        },
      },
    },
  },
  scales: {
    x: {
      grid: { display: true, color: 'rgba(26, 37, 53, 0.4)' },
      ticks: { color: 'var(--muted)', font: { size: 9, family: 'var(--mono)' }, maxRotation: 0, autoSkip: true },
    },
    y: {
      grid: { display: true, color: 'rgba(26, 37, 53, 0.4)' },
      ticks: { color: 'var(--muted)', font: { size: 9, family: 'var(--mono)' }, callback: (value: number) => value.toLocaleString() },
    },
  },
  elements: {
    line: { tension: 0.3, borderWidth: 2, borderCapStyle: 'round', borderJoinStyle: 'round' },
    point: { radius: 0, hoverRadius: 6, borderWidth: 2 },
    bar: { borderRadius: 4, borderSkipped: false },
  },
};

export class BaseChart {
  protected chart: Chart | null = null;
  protected container: HTMLElement;
  protected canvas: HTMLCanvasElement;
  protected config: ChartConfiguration;

  constructor(protected options: ChartOptions) {
    this.container = options.container;
    this.config = { ...DEFAULT_CONFIG, ...options.options };
    this.canvas = this.createCanvas();
    this.container.appendChild(this.canvas);
    this.render();
  }

  private createCanvas(): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.display = 'block';
    return canvas;
  }

  protected getChartJSConfig(): any {
    const { data, type } = this.options;
    const isArea = type === 'area';

    const datasets = data.datasets.map(ds => ({
      ...ds,
      fill: isArea || ds.fill,
      backgroundColor: ds.backgroundColor || this.getAreaFill(ds.borderColor),
      borderColor: ds.borderColor,
      pointBackgroundColor: ds.pointBackgroundColor || ds.borderColor,
      pointBorderColor: 'var(--bg)',
      pointBorderWidth: 2,
    }));

    return {
      type: isArea ? 'line' : type,
      data: {
        labels: data.labels,
        datasets,
      },
      options: this.config,
    };
  }

  private getAreaFill(borderColor: string): string {
    // Convert border color to 10% opacity fill
    const rootStyles = getComputedStyle(document.documentElement);
    const surface = rootStyles.getPropertyValue('--bg2').trim() || '#0b0f18';
    // Use the border color with low opacity
    return borderColor.replace(')', ', 0.1)').replace('rgb', 'rgba').replace('#', '').replace(/^([0-9a-f]{6})$/i, (_, hex) => {
      const r = parseInt(hex.slice(0, 2), 16);
      const g = parseInt(hex.slice(2, 4), 16);
      const b = parseInt(hex.slice(4, 6), 16);
      return `rgba(${r}, ${g}, ${b}, 0.1)`;
    });
  }

  render(): void {
    if (this.chart) {
      this.chart.destroy();
    }

    // Wait for canvas to have dimensions
    if (this.canvas.clientWidth === 0) {
      requestAnimationFrame(() => this.render());
      return;
    }

    try {
      this.chart = new Chart(this.canvas, this.getChartJSConfig());
      this.options.onRender?.(this.chart);
    } catch (error) {
      console.error('[Chart] Render error:', error);
    }
  }

  updateData(data: ChartData): void {
    this.options.data = data;
    if (this.chart) {
      this.chart.data.labels = data.labels;
      this.chart.data.datasets = data.datasets.map((ds, i) => ({
        ...this.chart!.data.datasets[i],
        ...ds,
        fill: this.options.type === 'area' || ds.fill,
        backgroundColor: ds.backgroundColor || this.getAreaFill(ds.borderColor),
      }));
      this.chart.update('none');
    }
  }

  updateOptions(options: Partial<ChartConfiguration>): void {
    this.config = { ...this.config, ...options };
    if (this.chart) {
      this.chart.options = { ...this.chart.options, ...options };
      this.chart.update();
    }
  }

  resize(): void {
    if (this.chart) {
      this.chart.resize();
    }
  }

  destroy(): void {
    if (this.chart) {
      this.chart.destroy();
      this.chart = null;
    }
    if (this.canvas.parentNode) {
      this.canvas.parentNode.removeChild(this.canvas);
    }
  }
}

// Chart type creators
export function createLineChart(options: ChartOptions): BaseChart {
  return new BaseChart({ ...options, type: 'line' });
}

export function createAreaChart(options: ChartOptions): BaseChart {
  return new BaseChart({ ...options, type: 'area' });
}

export function createBarChart(options: ChartOptions): BaseChart {
  return new BaseChart({ ...options, type: 'bar' });
}

// Helper to create chart datasets following dataviz rules
export function createDataset(
  label: string,
  data: number[],
  colorIndex: number = 0,
  options: { fill?: boolean; tension?: number } = {}
): ChartDataset {
  // Categorical palette (validated in dataviz skill)
  const colors = {
    light: [
      '#2a78d6', // blue - slot 1
      '#eb6834', // orange - slot 2
      '#1baf7a', // aqua - slot 3
      '#eda100', // yellow - slot 4
      '#e87ba4', // magenta - slot 5
      '#008300', // green - slot 6
      '#4a3aa7', // violet - slot 7
      '#e34948', // red - slot 8
    ],
    dark: [
      '#3987e5', // blue
      '#d95926', // orange
      '#199e70', // aqua
      '#c98500', // yellow
      '#d55181', // magenta
      '#008300', // green
      '#9085e9', // violet
      '#e66767', // red
    ],
  };

  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const palette = isDark ? colors.dark : colors.light;
  const color = palette[colorIndex % palette.length];

  return {
    label,
    data,
    borderColor: color,
    backgroundColor: options.fill ? `${color}20` : undefined, // ~10% opacity
    fill: options.fill ?? false,
    tension: options.tension ?? 0.3,
    pointRadius: 0,
    pointHoverRadius: 6,
    pointBackgroundColor: color,
    pointBorderColor: 'var(--bg)',
    pointBorderWidth: 2,
  };
}

// Status color dataset (for good/warning/serious/critical)
export function createStatusDataset(
  label: string,
  data: number[],
  status: 'good' | 'warning' | 'serious' | 'critical'
): ChartDataset {
  const statusColors = {
    good: '#0ca30c',
    warning: '#fab219',
    serious: '#ec835a',
    critical: '#d03b3b',
  };

  const color = statusColors[status];

  return {
    label,
    data,
    borderColor: color,
    backgroundColor: `${color}20`,
    fill: true,
    tension: 0.3,
    pointRadius: 0,
    pointHoverRadius: 6,
    pointBackgroundColor: color,
    pointBorderColor: 'var(--bg)',
    pointBorderWidth: 2,
  };
}

// Sequential dataset (single hue, light->dark for magnitude)
export function createSequentialDataset(
  label: string,
  data: number[],
  step: number = 400 // 400 = main blue step
): ChartDataset {
  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const blueSteps = {
    light: ['#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281', '#0d366b'],
    dark: ['#0d366b', '#104281', '#184f95', '#1c5cab', '#256abf', '#2a78d6', '#3987e5', '#5598e7', '#6da7ec', '#86b6ef', '#9ec5f4', '#b7d3f6', '#cde2fb'],
  };

  const palette = isDark ? blueSteps.dark : blueSteps.light;
  const safeStep = Math.min(step / 50, palette.length - 1);
  const mainIdx = Math.floor(safeStep * (palette.length - 1));
  const color = palette[mainIdx];

  return {
    label,
    data,
    borderColor: color,
    backgroundColor: `${color}1A`, // ~10%
    fill: true,
    tension: 0.3,
    pointRadius: 0,
    pointHoverRadius: 6,
    pointBackgroundColor: color,
    pointBorderColor: 'var(--bg)',
    pointBorderWidth: 2,
  };
}