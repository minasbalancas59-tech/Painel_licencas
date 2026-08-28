-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 04: revendedores, estoque de licencas, transferencias
--               de maquina e licencas de demonstracao.
--
--  Aplicar DEPOIS das migracoes anteriores:
--      sudo mysql licencas < sql/04_revendedores.sql
--
--  Migracao ADITIVA e idempotente: pode rodar mais de uma vez sem erro
--  e sem perder dados. Nada existente deixa de funcionar.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Helper idempotente (mesmo padrao da migracao 02)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col4;
DELIMITER //
CREATE PROCEDURE _add_col4(
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
-- 1) LICENCAS - estoque do revendedor, transferencias e tipo
--
--    revendedor_id      -> a quem a licenca foi atribuida (NULL = venda
--                          direta sua, comportamento atual)
--    transferencias     -> quantas vezes a maquina ja foi liberada
--    max_transferencias -> teto antes de exigir nova emissao (padrao 3)
--    tipo_licenca       -> 'venda' (padrao) ou 'demo' (demonstracao do
--                          revendedor: vincula/desvincula a vontade)
-- ---------------------------------------------------------------------
CALL _add_col4('licencas','revendedor_id',
    'revendedor_id INT NULL AFTER cliente_id');
CALL _add_col4('licencas','transferencias',
    'transferencias SMALLINT NOT NULL DEFAULT 0 AFTER tipo_ativacao');
CALL _add_col4('licencas','max_transferencias',
    'max_transferencias SMALLINT NOT NULL DEFAULT 3 AFTER transferencias');
CALL _add_col4('licencas','tipo_licenca',
    "tipo_licenca ENUM('venda','demo') NOT NULL DEFAULT 'venda' AFTER status");

-- cliente_id passa a aceitar NULL: a licenca nasce no estoque do
-- revendedor e so ganha cliente quando ele vincula.
-- (idempotente: MODIFY com a mesma definicao nao causa erro)
SET FOREIGN_KEY_CHECKS=0;
ALTER TABLE licencas MODIFY COLUMN cliente_id INT NULL;
SET FOREIGN_KEY_CHECKS=1;

-- ---------------------------------------------------------------------
-- 2) CLIENTES - a quem pertence o cadastro
--    Cliente cadastrado pelo revendedor fica marcado com o id dele.
--    NULL = cliente seu (direto), comportamento atual.
-- ---------------------------------------------------------------------
CALL _add_col4('clientes','revendedor_id',
    'revendedor_id INT NULL AFTER criado_por');

-- ---------------------------------------------------------------------
-- 3) SOLICITACOES DE TROCA DE CLIENTE
--    O revendedor pede; o admin aprova ou nega. Sem aprovacao, uma
--    licenca vinculada nao muda de cliente.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trocas_cliente (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    licenca_id      INT           NOT NULL,
    revendedor_id   INT           NOT NULL,
    cliente_atual   INT           NULL,
    cliente_novo    INT           NOT NULL,
    motivo          TEXT          NULL,
    status          ENUM('pendente','aprovada','negada')
                                  NOT NULL DEFAULT 'pendente',
    decidido_por    INT           NULL,
    decidido_em     DATETIME      NULL,
    observacao_admin TEXT         NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_troca_lic FOREIGN KEY (licenca_id) REFERENCES licencas(id),
    INDEX idx_troca_status (status),
    INDEX idx_troca_rev (revendedor_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4) INDICES das colunas novas
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_idx4;
DELIMITER //
CREATE PROCEDURE _add_idx4(
    IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_name
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX ',
                        p_name, ' (', p_cols, ')');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL _add_idx4('licencas','idx_lic_revendedor','revendedor_id');
CALL _add_idx4('clientes','idx_cli_revendedor','revendedor_id');

DROP PROCEDURE _add_col4;
DROP PROCEDURE _add_idx4;

-- =====================================================================
--  NOTA sobre as licencas que ja existem
--  ---------------------------------------------------------------------
--  Todas ficam com revendedor_id = NULL (suas, venda direta),
--  tipo_licenca = 'venda' e transferencias = 0. Ou seja: continuam
--  exatamente como estao hoje, e voce segue vendo e gerenciando tudo.
--
--  As acoes novas no log (`ativacoes_log.acao`, VARCHAR(40) livre):
--    atribuir_revendedor, vincular_cliente, liberar_maquina,
--    solicitar_troca, aprovar_troca, negar_troca
-- =====================================================================

-- =====================================================================
--  CONFERENCIA (rode manualmente depois):
--
--  SHOW COLUMNS FROM licencas LIKE 'revendedor_id';
--  SHOW COLUMNS FROM licencas LIKE 'tipo_licenca';
--  SHOW COLUMNS FROM clientes LIKE 'revendedor_id';
--  SHOW TABLES LIKE 'trocas_cliente';
--  SELECT COUNT(*) FROM licencas WHERE revendedor_id IS NULL;
-- =====================================================================
