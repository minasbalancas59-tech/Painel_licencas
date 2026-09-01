-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 16: valores das licencas e movimentacao financeira
--
--  Aplicar depois da 15:
--      mysql licencas < sql/16_valores.sql
--
--  MODELO
--
--  1. TABELA BASE            tiers.preco_base
--     Quanto custa cada tipo de licenca. E referencia, nao imposicao:
--     o valor cobrado e sempre editavel na emissao.
--
--  2. DESCONTO DO REVENDEDOR usuarios.desconto_revenda
--     Percentual que ele ganha sobre a tabela. Ele revende pelo preco
--     dele - isso NAO e controlado aqui, porque nao e sua receita.
--
--  3. MOVIMENTO              financeiro_mov
--     Cada emissao e cada renovacao gera uma linha com data e valor.
--     Guardar o valor so na licenca nao serviria: uma licenca renovada
--     tres vezes teria um valor e tres receitas diferentes, e nao daria
--     para dizer quanto entrou em cada mes.
--
--  SUA RECEITA e sempre o que VOCE recebeu: do cliente na venda direta,
--  do revendedor na revenda. A margem do revendedor nao entra.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col16;
DELIMITER //
CREATE PROCEDURE _add_col16(
    IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

-- preco de tabela por tipo de licenca
CALL _add_col16('tiers','preco_base',
    'preco_base DECIMAL(10,2) NULL AFTER nivel');

-- percentual de desconto do revendedor sobre a tabela
CALL _add_col16('usuarios','desconto_revenda',
    'desconto_revenda DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER limite_estoque');

-- valor efetivamente cobrado nesta licenca (ultimo movimento)
CALL _add_col16('licencas','valor',
    'valor DECIMAL(10,2) NULL AFTER tipo_licenca');

DROP PROCEDURE _add_col16;

-- ---------------------------------------------------------------------
--  movimentacao: uma linha por evento de receita
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS financeiro_mov (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    licenca_id    INT           NOT NULL,
    tipo          ENUM('emissao','renovacao','ajuste')
                                NOT NULL DEFAULT 'emissao',
    valor         DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- referencia da tabela na data, para saber o desconto concedido
    valor_tabela  DECIMAL(10,2) NULL,
    meses         SMALLINT      NULL,   -- periodo pago
    -- guardados aqui tambem porque o vinculo da licenca pode mudar
    cliente_id    INT           NULL,
    revendedor_id INT           NULL,
    produto       VARCHAR(20)   NULL,
    tier          VARCHAR(30)   NULL,
    competencia   CHAR(7)       NOT NULL,   -- '2026-08'
    observacao    VARCHAR(255)  NULL,
    criado_por    INT           NULL,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_lic FOREIGN KEY (licenca_id)
        REFERENCES licencas(id) ON DELETE CASCADE,
    INDEX idx_mov_comp (competencia),
    INDEX idx_mov_rev (revendedor_id, competencia),
    INDEX idx_mov_cli (cliente_id),
    INDEX idx_mov_tipo (tipo, competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  precos iniciais - AJUSTE ESTES VALORES antes de usar
--  Deixados em NULL de proposito: um preco chutado que vira padrao na
--  emissao e pior que nenhum preco.
-- ---------------------------------------------------------------------
-- Exemplo de como preencher:
--   UPDATE tiers t JOIN produtos p ON p.id=t.produto_id
--      SET t.preco_base = 1200.00
--    WHERE p.codigo='ts5' AND t.codigo='basico';

-- =====================================================================
--  CONFERENCIA:
--  SELECT p.codigo, t.codigo, t.nivel, t.preco_base
--    FROM tiers t JOIN produtos p ON p.id=t.produto_id ORDER BY 1,3;
--  SHOW TABLES LIKE 'financeiro_mov';
-- =====================================================================
