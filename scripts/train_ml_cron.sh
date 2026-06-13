#!/bin/bash
# train_ml_cron.sh — Wrapper para cron del ML trainer
# Uso en crontab: 0 */6 * * * /bin/bash /home/erika/web/binance.gregorbritez.cat/public_html/train_ml_cron.sh
cd /home/erika/web/binance.gregorbritez.cat/public_html
LOGFILE="train_ml_cron.log"
echo "=== $(date '+%Y-%m-%d %H:%M:%S') — Iniciando entrenamiento ===" >> "$LOGFILE"
python3 train_ml_weights.py \
  --type classifier \
  --symbol ETHUSDT \
  --horizon 4 \
  --up_thr 0.5 \
  --down_thr 0.5 \
  --c_reg 0.1 \
  --candles 40000 \
  --model logistic >> "$LOGFILE" 2>&1
echo "=== $(date '+%Y-%m-%d %H:%M:%S') — Entrenamiento finalizado ===" >> "$LOGFILE"
