-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 02: multi-produto, tiers cumulativos e auditoria de quem
--               ativou/desativou.
--
--  Banco: MariaDB 10.6  |  Charset: utf8mb4_unicode_ci
--  Aplicar DEPOIS de 01_schema.sql:
--      sudo mysql licencas < /root/licenca/sql/02_multiproduto.sql
--
--  Alinhada ao 01_schema.sql real:
--    - reaproveita a coluna `criado_por` da tabela `licencas`
--    - usa a validade existente `expira_em`
--    - ESTENDE a tabela `ativacoes_log` (nao cria log paralelo)
--
--  Migracao ADITIVA e idempotente: pode rodar mais de uma vez sem erro
--  e sem perder dados.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Helper idempotente para adicionar coluna so se ela ainda nao existir
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col;
DELIMITER //
CREATE PROCEDURE _add_col(
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

-- ---------------------------------------------------------------------
-- 1) PRODUTOS  (catalogo dos softwares licenciaveis)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS produtos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    codigo      VARCHAR(20)   NOT NULL UNIQUE,     -- ts5, ts6, outroA...
    nome        VARCHAR(80)   NOT NULL,
    ativo       TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2) TIERS  (niveis de cada produto; cumulativos via coluna `nivel`)
--    Regra de liberacao no Delphi:
--        liberado = nivel_da_licenca >= nivel_exigido_da_funcao
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tiers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    produto_id  INT           NOT NULL,
    codigo      VARCHAR(30)   NOT NULL,            -- light, basico, cameras...
    nome        VARCHAR(80)   NOT NULL,
    nivel       SMALLINT      NOT NULL,            -- 1,2,3... cumulativo
    ativo       TINYINT(1)    NOT NULL DEFAULT 1,
    UNIQUE KEY uq_tiers_produto_codigo (produto_id, codigo),
    UNIQUE KEY uq_tiers_produto_nivel  (produto_id, nivel),
    CONSTRAINT fk_tiers_produto
        FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3) LICENCAS - colunas novas
--    produto_id / tier_id  -> qual software e qual nivel a licenca libera
--    carencia_dias         -> dias apos expira_em antes de bloquear
--    (criado_por ja existe: e o usuario que emitiu; nao duplicamos)
-- ---------------------------------------------------------------------
CALL _add_col('licencas','produto_id',
    'produto_id INT NULL AFTER cliente_id');
CALL _add_col('licencas','tier_id',
    'tier_id INT NULL AFTER produto_id');
CALL _add_col('licencas','carencia_dias',
    'carencia_dias SMALLINT NOT NULL DEFAULT 15 AFTER expira_em');

-- FKs das colunas novas (bloco tolerante a "ja existe")
DROP PROCEDURE IF EXISTS _add_fk;
DELIMITER //
CREATE PROCEDURE _add_fk(
    IN p_name VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = p_name
    ) THEN
        SET @s = CONCAT('ALTER TABLE licencas ADD CONSTRAINT ', p_name, ' ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL _add_fk('fk_lic_produto',
    'FOREIGN KEY (produto_id) REFERENCES produtos(id)');
CALL _add_fk('fk_lic_tier',
    'FOREIGN KEY (tier_id) REFERENCES tiers(id)');

-- ---------------------------------------------------------------------
-- 4) ATIVACOES_LOG - colunas novas (estende o log que ja existe)
--    usuario_id     -> quem, no painel, fez a acao (emitir/revogar...)
--    usuario_nome   -> snapshot do nome (historico sobrevive a mudancas)
--    produto_codigo -> snapshot do produto no momento da acao
--    tier_codigo    -> snapshot do tier no momento da acao
--
--    A coluna `acao` do 01_schema ja e VARCHAR(40) livre, entao os novos
--    valores (emitir, ativar, desativar, reativar) entram sem alteracao.
-- ---------------------------------------------------------------------
CALL _add_col('ativacoes_log','usuario_id',
    'usuario_id INT NULL AFTER licenca_id');
CALL _add_col('ativacoes_log','usuario_nome',
    'usuario_nome VARCHAR(120) NULL AFTER usuario_id');
CALL _add_col('ativacoes_log','produto_codigo',
    'produto_codigo VARCHAR(20) NULL AFTER fingerprint');
CALL _add_col('ativacoes_log','tier_codigo',
    'tier_codigo VARCHAR(30) NULL AFTER produto_codigo');

-- NOTA: `usuario_id` em ativacoes_log fica SEM foreign key de proposito.
-- Log e historico: precisa sobreviver mesmo que o usuario seja removido,
-- entao nao amarramos por integridade referencial.

DROP PROCEDURE _add_col;
DROP PROCEDURE _add_fk;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED - produtos e tiers (CUMULATIVOS: nivel maior inclui os menores)
-- =====================================================================

INSERT INTO produtos (codigo, nome) VALUES
    ('ts5', 'Total Scale 5'),
    ('ts6', 'Total Scale 6')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Total Scale 5: light < basico < cameras < eixos < extreme
INSERT INTO tiers (produto_id, codigo, nome, nivel)
SELECT p.id, t.codigo, t.nome, t.nivel
FROM produtos p
JOIN (
    SELECT 'light'   AS codigo, 'Light'   AS nome, 1 AS nivel UNION ALL
    SELECT 'basico',  'Basico',  2 UNION ALL
    SELECT 'cameras', 'Cameras', 3 UNION ALL
    SELECT 'eixos',   'Eixos',   4 UNION ALL
    SELECT 'extreme', 'Extreme', 5
) t
WHERE p.codigo = 'ts5'
ON DUPLICATE KEY UPDATE nome = VALUES(nome), nivel = VALUES(nivel);

-- Total Scale 6: light < basico < cameras < automacao
INSERT INTO tiers (produto_id, codigo, nome, nivel)
SELECT p.id, t.codigo, t.nome, t.nivel
FROM produtos p
JOIN (
    SELECT 'light'     AS codigo, 'Light'     AS nome, 1 AS nivel UNION ALL
    SELECT 'basico',    'Basico',    2 UNION ALL
    SELECT 'cameras',   'Cameras',   3 UNION ALL
    SELECT 'automacao', 'Automacao', 4
) t
WHERE p.codigo = 'ts6'
ON DUPLICATE KEY UPDATE nome = VALUES(nome), nivel = VALUES(nivel);

-- ---------------------------------------------------------------------
-- MODELO para um software de tier UNICO no futuro (descomente e ajuste):
--
-- INSERT INTO produtos (codigo, nome) VALUES ('outroA','Outro Software A')
--     ON DUPLICATE KEY UPDATE nome = VALUES(nome);
-- INSERT INTO tiers (produto_id, codigo, nome, nivel)
-- SELECT id, 'unico', 'Licenca', 1 FROM produtos WHERE codigo = 'outroA'
--     ON DUPLICATE KEY UPDATE nome = VALUES(nome);
-- ---------------------------------------------------------------------

-- =====================================================================
--  CONFERENCIA (rode manualmente depois):
--
--  SELECT p.codigo AS produto, t.codigo AS tier, t.nivel
--  FROM tiers t JOIN produtos p ON p.id = t.produto_id
--  ORDER BY p.codigo, t.nivel;
--  -> 5 tiers para ts5, 4 para ts6, em ordem de nivel.
--
--  SHOW COLUMNS FROM licencas LIKE 'produto_id';
--  SHOW COLUMNS FROM ativacoes_log LIKE 'usuario_id';
-- =====================================================================
