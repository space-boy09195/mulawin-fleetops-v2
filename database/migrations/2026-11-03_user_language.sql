-- 2026-11-03: wika ng screen bawat account (English o Tagalog).
-- Para lang sa EXISTING database (halimbawa ang live sa Hostinger). Ang bagong install ay kasama na ito sa schema.sql.
-- Ligtas i-import kahit dalawang beses: idinadagdag lang ang column kapag wala pa.
-- Paano: phpMyAdmin > piliin ang database > Import > ang file na ito. Mag-backup muna.

SET @has_language := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'language');
SET @sql := IF(@has_language = 0,
  'ALTER TABLE users ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''en'' COMMENT ''Screen language: en (English, default) or tl (Tagalog)'' AFTER shift',
  'SELECT ''users.language already exists'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
