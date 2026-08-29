-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 08: dados de empresa dos revendedores
--
--  Aplicar depois da 07:
--      mysql licencas < sql/08_revendedor_dados.sql
--
--  Ate aqui, "revendedor" era so um login (nome, email, senha). Para
--  emitir nota, cobrar e saber com quem se fala, falta o cadastro da
--  empresa. Aditiva e idempotente.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col8;
DELIMITER //
CREATE PROCEDURE _add_col8(
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

CALL _add_col8('usuarios','empresa',
    'empresa VARCHAR(160) NULL AFTER nome');
CALL _add_col8('usuarios','cnpj',
    'cnpj VARCHAR(20) NULL AFTER empresa');
CALL _add_col8('usuarios','telefone',
    'telefone VARCHAR(40) NULL AFTER cnpj');
CALL _add_col8('usuarios','municipio',
    'municipio VARCHAR(120) NULL AFTER telefone');
CALL _add_col8('usuarios','uf',
    'uf CHAR(2) NULL AFTER municipio');
CALL _add_col8('usuarios','observacao',
    'observacao TEXT NULL AFTER uf');

-- teto de licencas que o revendedor pode ter em estoque ao mesmo tempo.
-- NULL = sem limite (comportamento atual). Serve para nao emitir lote
-- maior do que o combinado comercialmente.
CALL _add_col8('usuarios','limite_estoque',
    'limite_estoque INT NULL AFTER observacao');

DROP PROCEDURE _add_col8;

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM usuarios LIKE 'empresa';
--  SELECT id,nome,empresa,papel FROM usuarios;
-- =====================================================================
