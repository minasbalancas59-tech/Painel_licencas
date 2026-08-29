-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 13: cadastro de modulos e padroes de vencimento
--
--  Aplicar depois da 12:
--      mysql licencas < sql/13_modulos.sql
--
--  Ate aqui, os modulos (TBE, RFID, LPR) eram checkboxes escritos a mao
--  no licencas.php. Cadastrar um modulo novo exigia editar PHP. Agora
--  vem do banco, com tela propria.
--
--  A coluna `licencas.modulos` continua sendo CSV: e o que entra no
--  payload assinado e o que o Delphi le em TemModulo(). Mudar isso
--  quebraria todas as licencas ja emitidas.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS modulos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    -- codigo entra no CSV de licencas.modulos e no payload assinado:
    -- so maiusculas, sem espaco nem acento
    codigo     VARCHAR(20)  NOT NULL,
    nome       VARCHAR(80)  NOT NULL,
    descricao  VARCHAR(255) NULL,
    -- NULL = disponivel para todos os softwares
    produto_id INT          NULL,
    ativo      TINYINT(1)   NOT NULL DEFAULT 1,
    ordem      SMALLINT     NOT NULL DEFAULT 0,
    criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_modulo (codigo, produto_id),
    CONSTRAINT fk_modulo_prod FOREIGN KEY (produto_id)
        REFERENCES produtos(id) ON DELETE CASCADE,
    INDEX idx_modulo_prod (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- os tres que estavam no codigo, agora no banco
INSERT IGNORE INTO modulos (codigo, nome, descricao, produto_id, ordem) VALUES
  ('TBE',  'TBE (pesagem)',  'Módulo de pesagem / ticket de balança', NULL, 1),
  ('RFID', 'RFID',           'Identificação por tag RFID',            NULL, 2),
  ('LPR',  'LPR (câmera)',   'Leitura automática de placas',          NULL, 3);

-- ---------------------------------------------------------------------
-- descricao do produto e do tier, para a tela de cadastro
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col13;
DELIMITER //
CREATE PROCEDURE _add_col13(
    IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table AND COLUMN_NAME = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL _add_col13('produtos','descricao', 'descricao VARCHAR(255) NULL AFTER nome');
CALL _add_col13('tiers','descricao',    'descricao VARCHAR(255) NULL AFTER nome');
DROP PROCEDURE _add_col13;

-- ---------------------------------------------------------------------
-- padroes de emissao e alertas, editaveis em Configuracoes
-- ---------------------------------------------------------------------
INSERT IGNORE INTO configuracoes (chave, valor, cifrado) VALUES
  ('validade_padrao_meses',   '12', 0),
  ('carencia_padrao_dias',    '15', 0),
  ('max_transf_padrao',       '3',  0),
  ('demo_validade_dias',      '30', 0),
  ('alerta_vencendo_dias',    '30', 0),
  ('alerta_sem_uso_dias',     '30', 0),
  ('revalidacao_dias',        '7',  0),
  ('tolerancia_offline_dias', '7',  0);

-- =====================================================================
--  CONFERENCIA:
--  SELECT * FROM modulos;
--  SHOW COLUMNS FROM produtos LIKE 'descricao';
--  SELECT chave,valor FROM configuracoes WHERE chave LIKE '%padrao%';
-- =====================================================================
