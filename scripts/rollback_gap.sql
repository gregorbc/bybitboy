-- Rollback del gap de paneles (2026-08-09) — DROP en orden inverso
DROP TABLE IF EXISTS `alertas_config`;
DROP TABLE IF EXISTS `logs_acceso`;
DROP TABLE IF EXISTS `logs_ia`;
ALTER TABLE `grid_configs` DROP COLUMN IF EXISTS `recovery_loss_pct`, DROP COLUMN IF EXISTS `max_daily_loss`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `totp_enabled`, DROP COLUMN IF EXISTS `totp_secret`;
