-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 03: dados da maquina licenciada (nome do PC, usuario, SO)
--
--  Banco: MariaDB 10.6
--  Aplicar depois da 02:
--      sudo mysql licencas < /root/licenca/sql/03_maquina.sql
--
--  Aditiva e idempotente.
-- =====================================================================

USE licencas;

DROP PROCEDURE IF EXISTS _add_col3;
DELIMITER //
CREATE PROCEDURE _add_col3(
    IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

-- dados da maquina onde a licenca foi ativada
CALL _add_col3('licencas','maq_nome',
    'maq_nome VARCHAR(120) NULL AFTER fingerprint');
CALL _add_col3('licencas','maq_usuario',
    'maq_usuario VARCHAR(120) NULL AFTER maq_nome');
CALL _add_col3('licencas','maq_so',
    'maq_so VARCHAR(120) NULL AFTER maq_usuario');

DROP PROCEDURE _add_col3;

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM licencas LIKE 'maq_%';
--  -> deve listar maq_nome, maq_usuario, maq_so
-- =====================================================================
