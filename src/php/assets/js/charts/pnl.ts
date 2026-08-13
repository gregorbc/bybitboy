// PnL Charts - Hourly, Daily, Cumulative

import { createAreaChart, createBarChart, createDataset, type ChartOptions } from './base';
import type { PnLData } from '../types';

export function createHourlyPnLChart(container: HTMLElement, data: PnLData['hourly']): void {
  if (!data?.length) {
    container.innerHTML = '<div class="no-data">Sin datos horarios</div>';
    return;
  }

  const labels = data.map(d => d.h.slice(-5)); // HH:MM
  const values = data.map(d => d.p);

  const chartOptions: ChartOptions = {
    container,
    data: {
      labels,
      datasets: [
        createDataset('PnL Horario', values, 0, { fill: true, tension: 0.3 }),
      ],
    },
    type: 'area',
    options: {
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx: any) => `PnL: ${ctx.parsed.y >= 0 ? '+' : ''}${ctx.parsed.y.toFixed(4)} USDT`,
          },
        },
      },
      scales: {
        y: {
          ticks: { callback: (v: number) => '$' + v.toFixed(2) },
        },
      },
    },
  };

  createAreaChart(chartOptions);
}

export function createDailyPnLChart(container: HTMLElement, data: PnLData['daily']): void {
  if (!data?.length) {
    container.innerHTML = '<div class="no-data">Sin datos diarios</div>';
    return;
  }

  const labels = data.map(d => d.d.slice(5)); // MM-DD
  const values = data.map(d => d.p);

  const colors = values.map(v => v >= 0 ? 'var(--green)' : 'var(--red)');

  const chartOptions: ChartOptions = {
    container,
    data: {
      labels,
      datasets: [{
        label: 'PnL Diario',
        data: values,
        backgroundColor: colors,
        borderColor: colors,
        borderWidth: 1,
        borderRadius: 4,
      }],
    },
    type: 'bar',
    options: {
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx: any) => `PnL: ${ctx.parsed.y >= 0 ? '+' : ''}${ctx.parsed.y.toFixed(4)} USDT`,
          },
        },
      },
      scales: {
        y: {
          ticks: { callback: (v: number) => '$' + v.toFixed(2) },
        },
      },
    },
  };

  createBarChart(chartOptions);
}

export function createCumulativePnLChart(container: HTMLElement, data: PnLData['cumulative']): void {
  if (!data?.length) {
    container.innerHTML = '<div class="no-data">Sin datos acumulados</div>';
    return;
  }

  const labels = data.map(d => d.d.slice(5));
  let acc = 0;
  const cumValues = data.map(d => { acc += d.p; return parseFloat(acc.toFixed(6)); });
  const isPositive = acc >= 0;
  const color = isPositive ? 'var(--green)' : 'var(--red)';

  const chartOptions: ChartOptions = {
    container,
    data: {
      labels,
      datasets: [
        createDataset('PnL Acumulado', cumValues, isPositive ? 2 : 7, { fill: true, tension: 0.3 }),
      ],
    },
    type: 'area',
    options: {
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx: any) => `Acumulado: ${ctx.parsed.y >= 0 ? '+' : ''}${ctx.parsed.y.toFixed(4)} USDT`,
          },
        },
      },
      scales: {
        y: {
          ticks: { callback: (v: number) => '$' + v.toFixed(2) },
        },
      },
    },
  };

  createAreaChart(chartOptions);
}

export function updateHourlyPnLChart(chart: any, data: PnLData['hourly']): void {
  if (!chart || !data?.length) return;
  const labels = data.map(d => d.h.slice(-5));
  const values = data.map(d => d.p);
  chart.data.labels = labels;
  chart.data.datasets[0].data = values;
  chart.update('none');
}

export function updateDailyPnLChart(chart: any, data: PnLData['daily']): void {
  if (!chart || !data?.length) return;
  const labels = data.map(d => d.d.slice(5));
  const values = data.map(d => d.p);
  const colors = values.map(v => v >= 0 ? 'var(--green)' : 'var(--red)');
  chart.data.labels = labels;
  chart.data.datasets[0].data = values;
  chart.data.datasets[0].backgroundColor = colors;
  chart.data.datasets[0].borderColor = colors;
  chart.update('none');
}

export function updateCumulativePnLChart(chart: any, data: PnLData['cumulative']): void {
  if (!chart || !data?.length) return;
  const labels = data.map(d => d.d.slice(5));
  let acc = 0;
  const cumValues = data.map(d => { acc += d.p; return parseFloat(acc.toFixed(6)); });
  const isPositive = acc >= 0;
  const color = isPositive ? 'var(--green)' : 'var(--red)';
  chart.data.labels = labels;
  chart.data.datasets[0].data = cumValues;
  chart.data.datasets[0].borderColor = color;
  chart.data.datasets[0].backgroundColor = `${color}1A`;
  chart.update('none');
}