-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 19: autocadastro do cliente final na ativacao
--
--  Aplicar depois da 18:
--      mysql licencas < sql/19_autocadastro.sql
--
--  CONTEXTO
--  Ha revendedor que nao quer aprender painel: recebe a chave e
--  repassa. Nesse caminho ninguem cadastra o cliente final - ate ele
--  ativar. O Total Scale entao exige o registro da empresa, e o
--  servidor cria o cliente ja vinculado a licenca e ao revendedor.
--
--  TRES DECISOES REGISTRADAS AQUI
--
--  1. CNPJ REPETIDO reaproveita o cadastro existente, nunca duplica.
--     Mas se o cliente ja era seu (venda direta) e agora aparece
--     comprando de um revendedor, reatribuir sozinho seria errado -
--     por isso a fila de conferencia.
--
--  2. DADOS NAO CONFERIDOS: telefone e e-mail vem do proprio cliente,
--     sem como validar. Marcados como nao conferidos para ninguem
--     cobrar confiando neles sem olhar.
--
--  3. RECEITA FORA DO AR nao bloqueia a ativacao. O cliente ficaria
--     parado por um servico que nao e nosso. Aceita o que ele digitou
--     e marca para conferir.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col19;
DELIMITER //
CREATE PROCEDURE _add_col19(
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

-- como o cliente entrou no sistema
CALL _add_col19('clientes','origem_cadastro',
    "origem_cadastro ENUM('painel','autocadastro') NOT NULL DEFAULT 'painel'
     AFTER revendedor_id");

-- 0 = dados vieram do cliente e ninguem olhou ainda
CALL _add_col19('clientes','conferido',
    'conferido TINYINT(1) NOT NULL DEFAULT 1 AFTER origem_cadastro');

-- razao social veio da Receita ou foi digitada pelo cliente?
CALL _add_col19('clientes','dados_receita',
    'dados_receita TINYINT(1) NOT NULL DEFAULT 0 AFTER conferido');

CALL _add_col19('clientes','autocadastro_em',
    'autocadastro_em DATETIME NULL AFTER dados_receita');

DROP PROCEDURE _add_col19;

-- ---------------------------------------------------------------------
--  fila de conferencia
--  Guarda o que o cliente digitou, mesmo quando o cadastro ja existia.
--  Sem isto, um CNPJ que ja estava no sistema entraria em silencio e
--  voce nunca saberia que aquele cliente passou a comprar de revenda.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS autocadastros (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    licenca_id    INT          NOT NULL,
    cliente_id    INT          NULL,   -- criado ou reaproveitado
    revendedor_id INT          NULL,   -- quem vendeu, vindo da licenca

    -- o que o cliente digitou, como digitou
    cnpj_informado     VARCHAR(20)  NULL,
    razao_informada    VARCHAR(160) NULL,
    contato_informado  VARCHAR(120) NULL,
    telefone_informado VARCHAR(40)  NULL,
    email_informado    VARCHAR(160) NULL,
    municipio_informado VARCHAR(120) NULL,
    uf_informada       CHAR(2)      NULL,

    -- o que aconteceu no servidor
    resultado     ENUM('criado','reaproveitado','conflito') NOT NULL,
    -- 'conflito' = CNPJ ja existia com OUTRO revendedor ou venda direta
    receita_ok    TINYINT(1)   NOT NULL DEFAULT 0,
    observacao    VARCHAR(255) NULL,

    revisado      TINYINT(1)   NOT NULL DEFAULT 0,
    revisado_por  INT          NULL,
    revisado_em   DATETIME     NULL,

    fingerprint   VARCHAR(80)  NULL,
    ip            VARCHAR(45)  NULL,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_auto_lic FOREIGN KEY (licenca_id)
        REFERENCES licencas(id) ON DELETE CASCADE,
    INDEX idx_auto_rev (revisado, criado_em),
    INDEX idx_auto_cli (cliente_id),
    INDEX idx_auto_res (resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- indice para achar cliente por CNPJ normalizado (so digitos)
DROP PROCEDURE IF EXISTS _add_idx19;
DELIMITER //
CREATE PROCEDURE _add_idx19()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'clientes' AND INDEX_NAME = 'idx_cli_cnpj'
    ) THEN
        ALTER TABLE clientes ADD INDEX idx_cli_cnpj (cnpj);
    END IF;
END //
DELIMITER ;
CALL _add_idx19();
DROP PROCEDURE _add_idx19;

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'autocadastros';
--  SHOW COLUMNS FROM clientes LIKE 'origem_cadastro';
--  SELECT origem_cadastro, COUNT(*) FROM clientes GROUP BY 1;
-- =====================================================================
