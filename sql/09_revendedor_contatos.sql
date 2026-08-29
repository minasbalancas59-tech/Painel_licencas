-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 09: nome fantasia e contatos dos revendedores
--
--  Aplicar depois da 08:
--      mysql licencas < sql/09_revendedor_contatos.sql
--
--  Coloca o cadastro de revendedor no mesmo nivel do de cliente.
--  Aditiva e idempotente.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col9;
DELIMITER //
CREATE PROCEDURE _add_col9(
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

-- nome fantasia da revenda (empresa = razao social)
CALL _add_col9('usuarios','nome_fantasia',
    'nome_fantasia VARCHAR(160) NULL AFTER empresa');

DROP PROCEDURE _add_col9;

-- ---------------------------------------------------------------------
-- CONTATOS DO REVENDEDOR
--   Tabela propria (nao reaproveita cliente_contatos) porque a chave
--   estrangeira aponta para `usuarios`, nao para `clientes`.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS revendedor_contatos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    revendedor_id INT          NOT NULL,
    nome          VARCHAR(120) NOT NULL,
    cargo         VARCHAR(80)  NULL,
    telefone      VARCHAR(40)  NULL,
    email         VARCHAR(160) NULL,
    observacao    VARCHAR(255) NULL,
    principal     TINYINT(1)   NOT NULL DEFAULT 0,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rcontato_user FOREIGN KEY (revendedor_id)
        REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_rcontato_rev (revendedor_id),
    INDEX idx_rcontato_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migra o responsavel ja cadastrado para a tabela de contatos
INSERT INTO revendedor_contatos (revendedor_id, nome, telefone, email, principal)
SELECT u.id, u.nome, NULLIF(TRIM(u.telefone),''), u.email, 1
  FROM usuarios u
 WHERE u.papel = 'revendedor'
   AND NOT EXISTS (
        SELECT 1 FROM revendedor_contatos rc WHERE rc.revendedor_id = u.id
   );

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM usuarios LIKE 'nome_fantasia';
--  SHOW TABLES LIKE 'revendedor_contatos';
--  SELECT COUNT(*) FROM revendedor_contatos;
-- =====================================================================
