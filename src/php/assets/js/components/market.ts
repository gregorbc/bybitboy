// Market Indicators Component

import type { MarketIndicators } from '../types';
import { formatPrice, formatPct, formatNumber } from '../utils/format';

export function renderMarketIndicators(indicators: MarketIndicators): void {
  updateElement('mRsi', indicators.rsi.toFixed(1));
  updateElement('mRsiLbl', indicators.rsiSignal);
  updateElement('mRsiBar', '', { width: `${Math.min(100, Math.max(0, indicators.rsi))}%` });
  updateElement('mRsiDot', '', { left: `${Math.min(100, Math.max(0, indicators.rsi))}%` });

  updateElement('mMacd', indicators.macd.toFixed(4));
  updateElement('mMacdLbl', indicators.macdSignal);
  const macdPct = Math.min(100, Math.max(0, 50 + (indicators.macd * 1000)));
  updateElement('mMacdBar', '', {
    width: `${macdPct}%`,
    background: indicators.macd >= 0 ? 'var(--green)' : 'var(--red)'
  });

  updateElement('mAdx', indicators.adx.toFixed(1));
  updateElement('mAdxLbl', indicators.adxSignal);
  updateElement('mAdxBar', '', { width: `${Math.min(100, indicators.adx)}%` });

  updateElement('mAtr', `${indicators.atrPct.toFixed(2)}%`);
  updateElement('mVolR', `Vol ratio: ${indicators.volRatio.toFixed(2)}`);

  updateElement('mFunding', formatPct(indicators.fundingRate * 100, 4));
  const nextFunding = new Date(indicators.nextFundingTime);
  updateElement('mFundNext', `Próximo: ${nextFunding.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}`);

  updateElement('mOi', formatNumber(indicators.openInterest));
  updateElement('mOiVal', `Valor: ${formatPrice(indicators.oiValue)}`);

  updateElement('mBb', indicators.bbPct.toFixed(1));
  updateElement('mBbRange', `${formatPrice(indicators.bbRange.lower)} - ${formatPrice(indicators.bbRange.upper)}`);

  updateElement('mE9', formatPrice(indicators.ema9));
  updateElement('mE21', formatPrice(indicators.ema21));
  updateElement('mE50', formatPrice(indicators.ema50));
}

function updateElement(id: string, text?: string, style?: Record<string, string>): void {
  const el = document.getElementById(id);
  if (!el) return;
  if (text !== undefined) el.textContent = text;
  if (style) Object.assign(el.style, style);
}

// RSI zone colors
export function getRsiZoneClass(rsi: number): string {
  if (rsi >= 70) return 'overbought';
  if (rsi <= 30) return 'oversold';
  return 'neutral';
}

export function getMacdSignalClass(macd: number): string {
  return macd >= 0 ? 'bullish' : 'bearish';
}