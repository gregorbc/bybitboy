//+------------------------------------------------------------------+
//| GridBotMT5.mq5                                                   |
//| Port base del grid PHP/Bybit para MetaTrader 5                   |
//+------------------------------------------------------------------+
#property strict
#property version   "1.00"
#property description "EA grid para MT5 basado en el bot PHP original."

#include <Trade/Trade.mqh>

CTrade trade;

input string          InpSymbol              = "";
input ulong           InpMagic               = 15430;
input double          InpCapitalUsd          = 30.0;
input int             InpLeverage            = 100;
input int             InpTimerSeconds        = 8;
input int             InpAiIntervalSeconds   = 120;
input ENUM_TIMEFRAMES InpTimeframe           = PERIOD_M5;
input int             InpCandlesFeed         = 150;
input int             InpLevels              = 16;
input int             InpLongLevels          = 8;
input int             InpShortLevels         = 8;
input double          InpBaseSpacing         = 0.0003;
input double          InpMinSpacing          = 0.0003;
input double          InpMaxSpacing          = 0.0012;
input int             InpAtrPeriod           = 14;
input double          InpAtrSpacingMult      = 0.28;
input double          InpMarginSafety        = 0.65;
input double          InpMinNotional         = 3.0;
input double          InpHardStopPct         = 3.0;
input double          InpMaxDailyLossPct     = 12.0;
input double          InpRecoveryLossPct     = 3.0;
input int             InpMinBuildIntervalSec = 90;
input double          InpMlBlendWeight       = 0.90;
input double          InpMlMinAccuracy       = 0.85;
input bool            InpCancelOnStart       = false;
input bool            InpAutoRebuild         = true;
input bool            InpRequireHedging      = true;

string   g_symbol;
int      g_atrHandle = INVALID_HANDLE;
datetime g_lastBuild = 0;
datetime g_lastAi = 0;
bool     g_recovery = false;
double   g_spacing = 0.0;
string   g_direction = "SIDEWAYS";
string   g_lastDirection = "SIDEWAYS";
int      g_directionChangeCount = 0;
int      g_confidence = 50;
int      g_longLevels = 8;
int      g_shortLevels = 8;

string ML_FEATURES[10] = {"rsi_14","stoch_14","macd_hist","ema_diff_9_21","vol_ratio","bb_width","atr_pct","vwap_ratio","spread_pct","momentum_5"};
string ML_CLASSES[3] = {"DOWN","SIDEWAYS","UP"};
double ML_INTERCEPTS[3] = {-0.9682429121886128, 1.9905114195188214, -1.0222685073302211};
double ML_MEAN[10] = {49.83630099127205,50.55532590797464,0.02084301672882055,-0.000010441450054220857,0.6959239549237002,0.004337989050640119,0.22809522083916484,0.9911467761654955,0.20396724074108535,0.00042970216715954024};
double ML_SCALE[10] = {23.014303949345447,45.228837516504086,1.3512780108723335,0.0018056485580494805,0.7748800132729732,0.004324789993857112,0.1731886353220096,0.13818294251745167,0.1995760465986797,0.3743204918844417};
double ML_W[10][3] = {
   {-0.23041249780931675,0.10394034262838255,0.12647215518093352},
   {-0.1010615277362736,-0.02825549026528436,0.12931701800155798},
   {0.028483437157422284,0.026207589800726586,-0.05469102695815027},
   {0.06744786660867308,-0.07494935800007524,0.00750149139140306},
   {0.034856893960113565,-0.042396371169773975,0.007539477209659302},
   {-0.0329195278849221,0.006355786099413342,0.026563741785506093},
   {0.2369078361020696,-0.4127171710880135,0.17580933498594759},
   {-0.06526106108442954,0.21800600916469123,-0.15274494808025907},
   {0.042825712161507545,-0.0803619097218872,0.03753619756037903},
   {0.013804793187475833,0.01755185281453112,-0.031356646002006994}
};
double ML_ACC = 0.8809553582593472;

double VOL_INTERCEPT = 0.2522055384259799;
double VOL_MEAN[10] = {48.423005565862894,48.608745031232885,-0.01771993967859764,-0.00019113487283671691,0.6924428493284616,0.0048729600080094845,0.25650513467090724,0.9629437477968184,0.2296883444424387,-0.016816947526177017};
double VOL_SCALE[10] = {21.88127099186628,47.517015171237226,1.248122655500926,0.0019177804098880504,0.8020733723415961,0.005107209258994639,0.21630284495061475,0.07741137726478431,0.22770643679580974,0.41891369939421885};
double VOL_W[10] = {-0.000923311165316731,-0.0018010660043072159,-0.007440684956809044,-0.005836604591647859,0.003533729558148193,0.001422733383435241,0.17978539619838318,-0.016250275094382448,0.015922681961291346,0.0007768437420810189};
double VOL_CLIP_LOWER = 0.1599;
double VOL_CLIP_UPPER = 0.4931;

int OnInit()
{
   g_symbol = (InpSymbol == "" ? _Symbol : InpSymbol);
   if(!SymbolSelect(g_symbol, true))
   {
      Print("No se pudo seleccionar simbolo: ", g_symbol);
      return INIT_FAILED;
   }

   long marginMode = AccountInfoInteger(ACCOUNT_MARGIN_MODE);
   if(InpRequireHedging && marginMode != ACCOUNT_MARGIN_MODE_RETAIL_HEDGING)
   {
      Print("Este grid necesita cuenta hedging para mantener compras y ventas simultaneas. Cambia la cuenta o desactiva InpRequireHedging bajo tu riesgo.");
      return INIT_FAILED;
   }

   g_atrHandle = iATR(g_symbol, InpTimeframe, InpAtrPeriod);
   if(g_atrHandle == INVALID_HANDLE)
   {
      Print("No se pudo crear indicador ATR.");
      return INIT_FAILED;
   }

   trade.SetExpertMagicNumber(InpMagic);
   trade.SetDeviationInPoints(20);
   EventSetTimer(MathMax(1, InpTimerSeconds));

   if(InpCancelOnStart)
      CancelPendingOrders();

   g_longLevels = MathMax(0, InpLongLevels);
   g_shortLevels = MathMax(0, InpShortLevels);
   g_spacing = CurrentSpacing();
   Print("GridBotMT5 iniciado en ", g_symbol, " spacing=", DoubleToString(g_spacing * 100.0, 4), "%");
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   if(g_atrHandle != INVALID_HANDLE)
      IndicatorRelease(g_atrHandle);
}

void OnTimer()
{
   TickCycle();
}

void OnTick()
{
   TickCycle();
}

void OnTradeTransaction(const MqlTradeTransaction &trans,
                        const MqlTradeRequest &request,
                        const MqlTradeResult &result)
{
   if(trans.type != TRADE_TRANSACTION_DEAL_ADD)
      return;
   if(!HistoryDealSelect(trans.deal))
      return;
   if(HistoryDealGetString(trans.deal, DEAL_SYMBOL) != g_symbol)
      return;
   if((ulong)HistoryDealGetInteger(trans.deal, DEAL_MAGIC) != InpMagic)
      return;

   g_lastBuild = 0;
}

void TickCycle()
{
   MqlTick tick;
   if(!SymbolInfoTick(g_symbol, tick) || tick.bid <= 0 || tick.ask <= 0)
      return;

   double price = (tick.bid + tick.ask) / 2.0;
   RiskCheck(price);
   if((TimeCurrent() - g_lastAi) >= InpAiIntervalSeconds)
      AiEvaluate(price);

   if(!InpAutoRebuild)
      return;

   int openGrid = CountPendingOrders();
   if(openGrid == 0)
      BuildGrid(price);
   else if(openGrid < MathMax(1, InpLevels - 3))
   {
      Print("Pocas ordenes abiertas: ", openGrid, ". Reconstruyendo grid.");
      CancelPendingOrders();
      BuildGrid(price);
   }
   else
      BreakoutCheck(price);
}

void BuildGrid(const double price)
{
   datetime now = TimeCurrent();
   if(g_lastBuild > 0 && (now - g_lastBuild) < InpMinBuildIntervalSec)
      return;

   if(g_spacing <= 0.0)
      g_spacing = CurrentSpacing();
   if(g_recovery)
      g_spacing = MathMin(InpMaxSpacing, g_spacing * 1.8);

   int longLevels = MathMax(0, g_longLevels);
   int shortLevels = MathMax(0, g_shortLevels);
   int totalLevels = MathMax(1, longLevels + shortLevels);
   double volume = CalcVolume(price, totalLevels);
   if(volume <= 0.0)
   {
      Print("Volumen calculado invalido.");
      return;
   }

   int placed = 0;
   for(int i = 1; i <= longLevels; i++)
   {
      double entry = NormalizePrice(price * (1.0 - g_spacing * i));
      double tp = NormalizePrice(entry * (1.0 + g_spacing));
      if(entry > 0.0 && PlaceBuyLimit(volume, entry, tp, i))
         placed++;
   }

   for(int i = 1; i <= shortLevels; i++)
   {
      double entry = NormalizePrice(price * (1.0 + g_spacing * i));
      double tp = NormalizePrice(entry * (1.0 - g_spacing));
      if(entry > 0.0 && PlaceSellLimit(volume, entry, tp, -i))
         placed++;
   }

   if(placed > 0)
   {
      g_lastBuild = now;
      Print("Grid construido: ", placed, " ordenes, lote=", DoubleToString(volume, 4),
            ", spacing=", DoubleToString(g_spacing * 100.0, 4), "%");
   }
}

bool PlaceBuyLimit(const double volume, const double price, const double tp, const int level)
{
   string comment = "GRID_ENTRY_L" + IntegerToString(level);
   if(!trade.BuyLimit(volume, price, g_symbol, 0.0, tp, ORDER_TIME_GTC, 0, comment))
   {
      Print("Error BuyLimit L", level, ": ", trade.ResultRetcode(), " ", trade.ResultRetcodeDescription());
      return false;
   }
   return true;
}

bool PlaceSellLimit(const double volume, const double price, const double tp, const int level)
{
   string comment = "GRID_ENTRY_L" + IntegerToString(level);
   if(!trade.SellLimit(volume, price, g_symbol, 0.0, tp, ORDER_TIME_GTC, 0, comment))
   {
      Print("Error SellLimit L", level, ": ", trade.ResultRetcode(), " ", trade.ResultRetcodeDescription());
      return false;
   }
   return true;
}

double CalcVolume(const double price, const int levels)
{
   double balance = AccountInfoDouble(ACCOUNT_BALANCE);
   if(balance <= 0.0)
      balance = InpCapitalUsd;

   double effectiveCap = MathMin(balance, InpCapitalUsd) * InpMarginSafety;
   double marginPerLevel = effectiveCap / MathMax(1, levels);
   double rawVolume = (marginPerLevel * InpLeverage) / price;

   double maxVolume = (effectiveCap * 0.12 * InpLeverage) / price;
   rawVolume = MathMin(rawVolume, maxVolume);

   double contractSize = SymbolInfoDouble(g_symbol, SYMBOL_TRADE_CONTRACT_SIZE);
   if(contractSize > 0.0)
   {
      double notional = rawVolume * contractSize * price;
      if(notional < InpMinNotional)
         rawVolume = InpMinNotional / (contractSize * price);
   }

   return NormalizeVolume(rawVolume);
}

double CurrentSpacing()
{
   double spacing = InpBaseSpacing;
   double atrPct = AtrPct();
   if(atrPct > 0.0)
      spacing = MathMax(spacing, (atrPct / 100.0) * InpAtrSpacingMult);

   spacing = MathMax(InpMinSpacing, MathMin(InpMaxSpacing, spacing));
   return spacing;
}

bool LoadCandles(MqlRates &rates[])
{
   int need = MathMax(30, InpCandlesFeed);
   ArraySetAsSeries(rates, true);
   int copied = CopyRates(g_symbol, InpTimeframe, 0, need, rates);
   return copied >= 30;
}

double CloseAt(MqlRates &rates[], const int n, const int chronologicalIndex)
{
   return rates[n - 1 - chronologicalIndex].close;
}

double HighAt(MqlRates &rates[], const int n, const int chronologicalIndex)
{
   return rates[n - 1 - chronologicalIndex].high;
}

double LowAt(MqlRates &rates[], const int n, const int chronologicalIndex)
{
   return rates[n - 1 - chronologicalIndex].low;
}

double VolumeAt(MqlRates &rates[], const int n, const int chronologicalIndex)
{
   return (double)rates[n - 1 - chronologicalIndex].tick_volume;
}

double RsiLast(MqlRates &rates[], const int period = 14)
{
   int n = ArraySize(rates);
   if(n <= period)
      return 50.0;

   double gain = 0.0;
   double loss = 0.0;
   for(int i = 1; i <= period; i++)
   {
      double diff = CloseAt(rates, n, i) - CloseAt(rates, n, i - 1);
      if(diff > 0.0)
         gain += diff;
      else
         loss += MathAbs(diff);
   }
   gain /= period;
   loss /= period;

   for(int i = period + 1; i < n; i++)
   {
      double diff = CloseAt(rates, n, i) - CloseAt(rates, n, i - 1);
      gain = (gain * (period - 1) + MathMax(diff, 0.0)) / period;
      loss = (loss * (period - 1) + MathMax(-diff, 0.0)) / period;
   }
   if(loss == 0.0)
      return 100.0;
   return 100.0 - 100.0 / (1.0 + gain / loss);
}

double EmaLast(MqlRates &rates[], const int period)
{
   int n = ArraySize(rates);
   if(n < period || period <= 0)
      return 0.0;

   double ema = 0.0;
   for(int i = 0; i < period; i++)
      ema += CloseAt(rates, n, i);
   ema /= period;

   double k = 2.0 / (period + 1.0);
   for(int i = period; i < n; i++)
      ema = CloseAt(rates, n, i) * k + ema * (1.0 - k);
   return ema;
}

void EmaCloses(MqlRates &rates[], const int period, double &out[])
{
   int n = ArraySize(rates);
   ArrayResize(out, n);
   for(int i = 0; i < n; i++)
      out[i] = 0.0;
   if(n < period || period <= 0)
      return;

   double ema = 0.0;
   for(int i = 0; i < period; i++)
      ema += CloseAt(rates, n, i);
   ema /= period;
   out[period - 1] = ema;

   double k = 2.0 / (period + 1.0);
   for(int i = period; i < n; i++)
   {
      ema = CloseAt(rates, n, i) * k + ema * (1.0 - k);
      out[i] = ema;
   }
}

double MacdHistLast(MqlRates &rates[])
{
   int n = ArraySize(rates);
   if(n < 35)
      return 0.0;

   double ema12[];
   double ema26[];
   EmaCloses(rates, 12, ema12);
   EmaCloses(rates, 26, ema26);

   double macd[];
   int m = 0;
   ArrayResize(macd, n);
   for(int i = 0; i < n; i++)
   {
      if(ema12[i] != 0.0 && ema26[i] != 0.0)
      {
         macd[m] = ema12[i] - ema26[i];
         m++;
      }
   }
   if(m < 9)
      return 0.0;

   double ema = 0.0;
   for(int i = 0; i < 9; i++)
      ema += macd[i];
   ema /= 9.0;

   double k = 2.0 / 10.0;
   for(int i = 9; i < m; i++)
      ema = macd[i] * k + ema * (1.0 - k);

   return macd[m - 1] - ema;
}

double AtrPctFromCandles(MqlRates &rates[], const int period = 14)
{
   int n = ArraySize(rates);
   if(n < 2)
      return 0.0;

   int start = MathMax(1, n - period);
   double sum = 0.0;
   int count = 0;
   for(int i = start; i < n; i++)
   {
      double high = HighAt(rates, n, i);
      double low = LowAt(rates, n, i);
      double prevClose = CloseAt(rates, n, i - 1);
      double tr = MathMax(high - low, MathMax(MathAbs(high - prevClose), MathAbs(low - prevClose)));
      sum += tr;
      count++;
   }

   double price = CloseAt(rates, n, n - 1);
   if(count <= 0 || price <= 0.0)
      return 0.0;
   return (sum / count) / price * 100.0;
}

double VolRatioLast(MqlRates &rates[])
{
   int n = ArraySize(rates);
   if(n < 20)
      return 1.0;

   double sum = 0.0;
   for(int i = n - 20; i < n; i++)
      sum += VolumeAt(rates, n, i);

   double avg = sum / 20.0;
   if(avg <= 0.0)
      return 1.0;
   return VolumeAt(rates, n, n - 1) / avg;
}

double BbWidth(MqlRates &rates[], const int period = 20)
{
   int n = ArraySize(rates);
   if(n < period)
      return 0.0;

   double avg = 0.0;
   int start = n - period;
   for(int i = start; i < n; i++)
      avg += CloseAt(rates, n, i);
   avg /= period;

   double var = 0.0;
   for(int i = start; i < n; i++)
   {
      double d = CloseAt(rates, n, i) - avg;
      var += d * d;
   }
   double std = MathSqrt(var / period);
   double last = CloseAt(rates, n, n - 1);
   if(last <= 0.0)
      return 0.0;
   return std * 4.0 / last * 100.0;
}

double StochLast(MqlRates &rates[], const int period = 14)
{
   int n = ArraySize(rates);
   if(n < period)
      return 50.0;

   int start = n - period;
   double hh = HighAt(rates, n, start);
   double ll = LowAt(rates, n, start);
   for(int i = start + 1; i < n; i++)
   {
      hh = MathMax(hh, HighAt(rates, n, i));
      ll = MathMin(ll, LowAt(rates, n, i));
   }
   if(hh - ll == 0.0)
      return 50.0;
   double lastClose = CloseAt(rates, n, n - 1);
   return (lastClose - ll) / (hh - ll) * 100.0;
}

void BuildFeatures(MqlRates &rates[], const double price, double &features[])
{
   int n = ArraySize(rates);
   ArrayResize(features, 10);
   features[0] = RsiLast(rates);
   features[1] = StochLast(rates);
   features[2] = MacdHistLast(rates);
   double ema9 = EmaLast(rates, 9);
   double ema21 = EmaLast(rates, 21);
   features[3] = (ema9 > 0.0 && ema21 > 0.0 && price > 0.0) ? ((ema9 - ema21) / price) : 0.0;
   features[4] = VolRatioLast(rates);
   features[5] = BbWidth(rates);
   features[6] = AtrPctFromCandles(rates);

   double cumTV = 0.0;
   double cumV = 0.0;
   for(int i = 0; i < n; i++)
   {
      double typ = (HighAt(rates, n, i) + LowAt(rates, n, i) + CloseAt(rates, n, i)) / 3.0;
      double vol = VolumeAt(rates, n, i);
      cumTV += typ * vol;
      cumV += vol;
   }
   double vwap = (cumV > 0.0 ? cumTV / cumV : price);
   features[7] = (vwap > 0.0 ? price / vwap : 1.0);

   double lastClose = CloseAt(rates, n, n - 1);
   features[8] = (lastClose > 0.0 ? (HighAt(rates, n, n - 1) - LowAt(rates, n, n - 1)) / lastClose * 100.0 : 0.0);
   if(n >= 6)
   {
      double prev = CloseAt(rates, n, n - 6);
      double curr = CloseAt(rates, n, n - 1);
      features[9] = (prev > 0.0 ? (curr - prev) / prev * 100.0 : 0.0);
   }
   else
      features[9] = 0.0;
}

void PredictMl(MqlRates &rates[], const double price, double &probs[], string &direction, int &confidence)
{
   ArrayResize(probs, 3);
   if(ML_ACC < InpMlMinAccuracy)
   {
      double rsi = RsiLast(rates);
      direction = (rsi > 58.0 ? "UP" : (rsi < 42.0 ? "DOWN" : "SIDEWAYS"));
      confidence = 35;
      probs[0] = 0.33;
      probs[1] = 0.34;
      probs[2] = 0.33;
      return;
   }

   double feats[];
   BuildFeatures(rates, price, feats);

   double scores[3];
   for(int c = 0; c < 3; c++)
   {
      scores[c] = ML_INTERCEPTS[c];
      for(int i = 0; i < 10; i++)
      {
         double scale = ML_SCALE[i];
         if(scale == 0.0)
            scale = 1.0;
         double scaled = (feats[i] - ML_MEAN[i]) / scale;
         scaled = MathMax(-3.0, MathMin(3.0, scaled));
         scores[c] += scaled * ML_W[i][c];
      }
   }

   double mx = MathMax(scores[0], MathMax(scores[1], scores[2]));
   double sum = 0.0;
   for(int c = 0; c < 3; c++)
   {
      probs[c] = MathExp(scores[c] - mx);
      sum += probs[c];
   }
   for(int c = 0; c < 3; c++)
      probs[c] /= sum;

   int maxIdx = 0;
   if(probs[1] > probs[maxIdx])
      maxIdx = 1;
   if(probs[2] > probs[maxIdx])
      maxIdx = 2;

   direction = ML_CLASSES[maxIdx];
   confidence = (int)MathRound(probs[maxIdx] * 100.0);
}

double PredictFutureAtr(MqlRates &rates[])
{
   if(ArraySize(rates) < 30)
      return 0.0;

   double price = CloseAt(rates, ArraySize(rates), ArraySize(rates) - 1);
   double feats[];
   BuildFeatures(rates, price, feats);

   double pred = VOL_INTERCEPT;
   for(int i = 0; i < 10; i++)
   {
      double scale = VOL_SCALE[i];
      if(scale == 0.0)
         scale = 1.0;
      double scaled = (feats[i] - VOL_MEAN[i]) / scale;
      pred += scaled * VOL_W[i];
   }

   double atrActual = feats[6];
   if(pred < 0.0)
      return 0.0;

   pred = MathMax(VOL_CLIP_LOWER, MathMin(VOL_CLIP_UPPER, pred));
   if(atrActual > 0.01)
   {
      double ratio = pred / atrActual;
      if(ratio < 0.5)
         pred = 0.4 * pred + 0.6 * atrActual;
      else if(ratio > 3.0)
         pred = 0.65 * atrActual + 0.35 * pred;
   }
   return pred;
}

void AiEvaluate(const double price)
{
   MqlRates rates[];
   if(!LoadCandles(rates))
   {
      g_lastAi = TimeCurrent();
      return;
   }

   double mlProbs[];
   string mlDir = "SIDEWAYS";
   int mlConf = 35;
   PredictMl(rates, price, mlProbs, mlDir, mlConf);

   double rsi = RsiLast(rates);
   double macd = MacdHistLast(rates);
   double ema9 = EmaLast(rates, 9);
   double ema21 = EmaLast(rates, 21);
   bool emaBull = (ema9 > 0.0 && ema21 > 0.0 && ema9 > ema21 && price > ema21);
   bool emaBear = (ema9 > 0.0 && ema21 > 0.0 && ema9 < ema21 && price < ema21);

   double hScore = 0.0;
   if(rsi > 55.0)
      hScore += 1.0;
   else if(rsi < 45.0)
      hScore -= 1.0;
   if(macd > 0.0)
      hScore += 0.5;
   else if(macd < 0.0)
      hScore -= 0.5;
   if(emaBull)
      hScore += 0.5;
   else if(emaBear)
      hScore -= 0.5;

   double norm = (hScore + 2.0) / 4.0;
   double hProbs[3];
   hProbs[0] = MathMax(0.0, 0.5 - norm);
   hProbs[1] = MathMax(0.0, MathAbs(0.5 - norm) * 0.4 + 0.2);
   hProbs[2] = MathMax(0.0, norm - 0.1);
   double hSum = hProbs[0] + hProbs[1] + hProbs[2];
   if(hSum > 0.0)
   {
      hProbs[0] /= hSum;
      hProbs[1] /= hSum;
      hProbs[2] /= hSum;
   }
   else
   {
      hProbs[0] = 0.33;
      hProbs[1] = 0.34;
      hProbs[2] = 0.33;
   }

   double wMl = MathMax(0.0, MathMin(1.0, InpMlBlendWeight));
   double wHeur = 1.0 - wMl;
   double blended[3];
   for(int i = 0; i < 3; i++)
      blended[i] = wMl * mlProbs[i] + wHeur * hProbs[i];

   int maxIdx = 0;
   if(blended[1] > blended[maxIdx])
      maxIdx = 1;
   if(blended[2] > blended[maxIdx])
      maxIdx = 2;

   string newDirection = ML_CLASSES[maxIdx];
   int newConfidence = (int)MathRound(blended[maxIdx] * 100.0);
   string prevDir = g_direction;

   if(newDirection != prevDir)
   {
      if(newDirection == g_lastDirection)
      {
         g_directionChangeCount++;
         if(g_directionChangeCount < 2)
         {
            newDirection = prevDir;
            newConfidence = (int)MathRound((newConfidence + g_confidence) / 2.0);
         }
         else
            g_directionChangeCount = 0;
      }
      else
      {
         g_lastDirection = newDirection;
         g_directionChangeCount = 1;
         newDirection = prevDir;
         newConfidence = (int)MathRound((newConfidence + g_confidence) / 2.0);
      }
   }
   else
   {
      g_directionChangeCount = 0;
      g_lastDirection = newDirection;
   }

   double atrActual = AtrPctFromCandles(rates);
   double atrPred = PredictFutureAtr(rates);
   double atrEffective = atrActual;
   if(atrPred > 0.01)
      atrEffective = 0.70 * atrActual + 0.30 * atrPred;

   double spacingRaw = InpBaseSpacing + (atrEffective * InpAtrSpacingMult / 100.0);
   double newSpacing = MathMin(InpMaxSpacing, MathMax(InpMinSpacing, spacingRaw));
   if(newDirection == "SIDEWAYS")
      newSpacing = MathMax(InpMinSpacing, newSpacing * 0.90);

   int levels = MathMax(1, InpLevels);
   int newLong = 0;
   int newShort = 0;
   if(newDirection == "UP")
   {
      newLong = (int)MathRound(levels * 0.625);
      newShort = levels - newLong;
   }
   else if(newDirection == "DOWN")
   {
      newShort = (int)MathRound(levels * 0.625);
      newLong = levels - newShort;
   }
   else
   {
      newLong = levels / 2;
      newShort = levels - newLong;
   }

   bool confirmedChange = (newDirection != prevDir && g_directionChangeCount == 0);
   g_direction = newDirection;
   g_confidence = newConfidence;
   g_spacing = newSpacing;
   g_longLevels = newLong;
   g_shortLevels = newShort;
   g_lastAi = TimeCurrent();

   Print("AI ", g_direction, " conf=", g_confidence, "% ML=", mlDir, "(", mlConf,
         "%) H=", DoubleToString(hScore, 1), " spacing=", DoubleToString(g_spacing * 100.0, 4),
         "% ATR=", DoubleToString(atrActual, 2), "% pred=", DoubleToString(atrPred, 2),
         "% L=", g_longLevels, " S=", g_shortLevels);

   if(confirmedChange)
   {
      Print("Cambio confirmado ", prevDir, " -> ", g_direction, ". Reconstruyendo grid.");
      CancelPendingOrders();
      g_lastBuild = 0;
   }
}

double AtrPct()
{
   if(g_atrHandle == INVALID_HANDLE)
      return 0.0;

   double atr[];
   ArraySetAsSeries(atr, true);
   if(CopyBuffer(g_atrHandle, 0, 0, 2, atr) <= 0)
      return 0.0;

   MqlTick tick;
   if(!SymbolInfoTick(g_symbol, tick))
      return 0.0;

   double price = (tick.bid + tick.ask) / 2.0;
   if(price <= 0.0)
      return 0.0;

   return (atr[0] / price) * 100.0;
}

void RiskCheck(const double price)
{
   double pnlToday = DailyProfit();
   double lossPct = (pnlToday < 0.0 ? MathAbs(pnlToday) / InpCapitalUsd * 100.0 : 0.0);

   if(lossPct >= InpRecoveryLossPct && !g_recovery)
   {
      g_recovery = true;
      Print("Recovery activado. Perdida diaria=", DoubleToString(lossPct, 2), "%");
      CancelPendingOrders();
      g_lastBuild = 0;
   }

   if(lossPct >= InpMaxDailyLossPct)
   {
      Print("Limite diario alcanzado. Cerrando posiciones y cancelando pendientes.");
      CancelPendingOrders();
      ClosePositions();
      g_lastBuild = TimeCurrent();
      return;
   }

   double contractSize = SymbolInfoDouble(g_symbol, SYMBOL_TRADE_CONTRACT_SIZE);
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0)
         continue;
      if(PositionGetString(POSITION_SYMBOL) != g_symbol)
         continue;
      if((ulong)PositionGetInteger(POSITION_MAGIC) != InpMagic)
         continue;

      double profit = PositionGetDouble(POSITION_PROFIT);
      double volume = PositionGetDouble(POSITION_VOLUME);
      double openPrice = PositionGetDouble(POSITION_PRICE_OPEN);
      double notional = MathAbs(volume * contractSize * openPrice);
      if(notional > 0.0 && profit < 0.0 && MathAbs(profit) / notional * 100.0 >= InpHardStopPct)
      {
         Print("Hard stop en ticket ", ticket, ". Cerrando posicion.");
         trade.PositionClose(ticket);
      }
   }

   if(g_recovery && pnlToday >= 0.0)
   {
      g_recovery = false;
      g_spacing = InpBaseSpacing;
      CancelPendingOrders();
      g_lastBuild = 0;
      Print("Recovery finalizado. PnL diario recuperado.");
   }
}

void BreakoutCheck(const double price)
{
   double minPrice = 0.0;
   double maxPrice = 0.0;
   int count = 0;

   for(int i = OrdersTotal() - 1; i >= 0; i--)
   {
      ulong ticket = OrderGetTicket(i);
      if(ticket == 0)
         continue;
      if(OrderGetString(ORDER_SYMBOL) != g_symbol)
         continue;
      if((ulong)OrderGetInteger(ORDER_MAGIC) != InpMagic)
         continue;

      ENUM_ORDER_TYPE type = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
      if(type != ORDER_TYPE_BUY_LIMIT && type != ORDER_TYPE_SELL_LIMIT)
         continue;

      double orderPrice = OrderGetDouble(ORDER_PRICE_OPEN);
      if(count == 0)
      {
         minPrice = orderPrice;
         maxPrice = orderPrice;
      }
      else
      {
         minPrice = MathMin(minPrice, orderPrice);
         maxPrice = MathMax(maxPrice, orderPrice);
      }
      count++;
   }

   if(count == 0 || maxPrice <= minPrice)
      return;

   double range = maxPrice - minPrice;
   double margin = range * 0.30;
   if(price < minPrice - margin || price > maxPrice + margin)
   {
      Print("Precio fuera de rango. Reconstruyendo grid.");
      CancelPendingOrders();
      g_lastBuild = 0;
      BuildGrid(price);
   }
}

int CountPendingOrders()
{
   int count = 0;
   for(int i = OrdersTotal() - 1; i >= 0; i--)
   {
      ulong ticket = OrderGetTicket(i);
      if(ticket == 0)
         continue;
      if(OrderGetString(ORDER_SYMBOL) != g_symbol)
         continue;
      if((ulong)OrderGetInteger(ORDER_MAGIC) != InpMagic)
         continue;

      ENUM_ORDER_TYPE type = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
      if(type == ORDER_TYPE_BUY_LIMIT || type == ORDER_TYPE_SELL_LIMIT)
         count++;
   }
   return count;
}

void CancelPendingOrders()
{
   for(int i = OrdersTotal() - 1; i >= 0; i--)
   {
      ulong ticket = OrderGetTicket(i);
      if(ticket == 0)
         continue;
      if(OrderGetString(ORDER_SYMBOL) != g_symbol)
         continue;
      if((ulong)OrderGetInteger(ORDER_MAGIC) != InpMagic)
         continue;

      ENUM_ORDER_TYPE type = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
      if(type == ORDER_TYPE_BUY_LIMIT || type == ORDER_TYPE_SELL_LIMIT)
         trade.OrderDelete(ticket);
   }
}

void ClosePositions()
{
   for(int i = PositionsTotal() - 1; i >= 0; i--)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0)
         continue;
      if(PositionGetString(POSITION_SYMBOL) != g_symbol)
         continue;
      if((ulong)PositionGetInteger(POSITION_MAGIC) != InpMagic)
         continue;

      trade.PositionClose(ticket);
   }
}

double DailyProfit()
{
   MqlDateTime dt;
   TimeToStruct(TimeCurrent(), dt);
   dt.hour = 0;
   dt.min = 0;
   dt.sec = 0;
   datetime dayStart = StructToTime(dt);

   if(!HistorySelect(dayStart, TimeCurrent()))
      return 0.0;

   double profit = 0.0;
   int deals = HistoryDealsTotal();
   for(int i = 0; i < deals; i++)
   {
      ulong deal = HistoryDealGetTicket(i);
      if(deal == 0)
         continue;
      if(HistoryDealGetString(deal, DEAL_SYMBOL) != g_symbol)
         continue;
      if((ulong)HistoryDealGetInteger(deal, DEAL_MAGIC) != InpMagic)
         continue;

      profit += HistoryDealGetDouble(deal, DEAL_PROFIT);
      profit += HistoryDealGetDouble(deal, DEAL_SWAP);
      profit += HistoryDealGetDouble(deal, DEAL_COMMISSION);
   }
   return profit;
}

double NormalizePrice(const double price)
{
   int digits = (int)SymbolInfoInteger(g_symbol, SYMBOL_DIGITS);
   return NormalizeDouble(price, digits);
}

double NormalizeVolume(const double volume)
{
   double minVol = SymbolInfoDouble(g_symbol, SYMBOL_VOLUME_MIN);
   double maxVol = SymbolInfoDouble(g_symbol, SYMBOL_VOLUME_MAX);
   double step = SymbolInfoDouble(g_symbol, SYMBOL_VOLUME_STEP);

   if(step <= 0.0)
      step = minVol;

   double normalized = MathRound(volume / step) * step;
   normalized = MathMax(minVol, MathMin(maxVol, normalized));

   int digits = 0;
   double probe = step;
   while(probe < 1.0 && digits < 8)
   {
      probe *= 10.0;
      digits++;
   }

   return NormalizeDouble(normalized, digits);
}
//+------------------------------------------------------------------+
