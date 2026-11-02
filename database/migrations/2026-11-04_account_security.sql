-- 2026-11-04: proteksyon ng accounts (lock pagkatapos ng 5 maling password, recovery code ng Admin,
-- at pag-log out ng lumang sessions kapag napalitan ang password).
-- Para lang sa EXISTING database (halimbawa ang live sa Hostinger). Ang bagong install ay kasama na ito sa schema.sql.
-- Ligtas i-import kahit dalawang beses: idinadagdag lang ang column kapag wala pa.
-- Paano: phpMyAdmin > piliin ang database > Import > ang file na ito. Mag-backup muna.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'failed_logins');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD COLUMN failed_logins INT NOT NULL DEFAULT 0 COMMENT ''Wrong passwords in a row (5 = locked)''', 'SELECT ''failed_logins already exists'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL COMMENT ''Account locked until this time after 5 wrong passwords''', 'SELECT ''locked_until already exists'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_hash');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD COLUMN recovery_hash VARCHAR(255) NULL COMMENT ''Admin recovery code (hashed; one-time use)''', 'SELECT ''recovery_hash already exists'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL COMMENT ''Older login sessions are signed out''', 'SELECT ''password_changed_at already exists'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
