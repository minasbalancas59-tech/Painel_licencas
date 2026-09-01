-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 20: versoes do sistema e registro de downloads
--
--  Aplicar depois da 19:
--      mysql licencas < sql/20_versoes.sql
--
--  MODELO
--
--  Cada produto tem varias versoes; UMA delas e a atual. O link que
--  voce divulga aponta para "a atual do TS5", nao para um arquivo
--  especifico - assim voce publica uma versao nova e quem tem o link
--  antigo ja baixa a nova, sem reenviar nada.
--
--  Cada versao tambem tem token proprio, para quando voce precisar
--  mandar alguem baixar uma versao especifica (um cliente que nao pode
--  atualizar agora, um teste de campo).
--
--  O ARQUIVO NAO FICA NO BANCO. Fica em /var/licenca_arquivos, fora do
--  webroot. Guardar binario de 150 MB em BLOB inflaria o dump diario
--  que sobe para o Drive.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS versoes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    produto_id  INT          NOT NULL,
    versao      VARCHAR(30)  NOT NULL,   -- '5.14.2'
    -- token do link direto desta versao especifica
    token       VARCHAR(32)  NOT NULL,
    arquivo     VARCHAR(255) NOT NULL,   -- nome no disco
    nome_original VARCHAR(255) NULL,     -- como veio do seu computador
    tamanho     BIGINT       NOT NULL DEFAULT 0,
    sha256      CHAR(64)     NULL,       -- confere se o download veio inteiro

    -- UMA por produto: e a que o link fixo entrega
    atual       TINYINT(1)   NOT NULL DEFAULT 0,
    -- versao retirada do ar sem apagar o arquivo
    publicada   TINYINT(1)   NOT NULL DEFAULT 1,

    notas       TEXT         NULL,       -- o que mudou nesta versao
    downloads   INT          NOT NULL DEFAULT 0,
    enviado_por INT          NULL,
    criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ver_prod FOREIGN KEY (produto_id)
        REFERENCES produtos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_ver_token (token),
    UNIQUE KEY uq_ver_prod (produto_id, versao),
    INDEX idx_ver_atual (produto_id, atual)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  quem baixou o que
--  Serve para saber se o parque atualizou depois de uma correcao
--  importante - e para descobrir de onde vem download em excesso.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS downloads_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    versao_id  INT          NOT NULL,
    ip         VARCHAR(45)  NULL,
    user_agent VARCHAR(255) NULL,
    referencia VARCHAR(60)  NULL,   -- de onde veio o link, se informado
    criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_ver FOREIGN KEY (versao_id)
        REFERENCES versoes(id) ON DELETE CASCADE,
    INDEX idx_dl_data (criado_em),
    INDEX idx_dl_ver (versao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- token do link fixo por produto: "sempre a versao atual do TS5"
DROP PROCEDURE IF EXISTS _add_col20;
DELIMITER //
CREATE PROCEDURE _add_col20(
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

CALL _add_col20('produtos','token_download',
    'token_download VARCHAR(32) NULL AFTER descricao');
DROP PROCEDURE _add_col20;

-- gera o token dos produtos que ainda nao tem
UPDATE produtos
   SET token_download = LOWER(SUBSTRING(SHA2(CONCAT(id, codigo, RAND()), 256), 1, 20))
 WHERE token_download IS NULL;

-- =====================================================================
--  CONFERENCIA:
--  SELECT codigo, token_download FROM produtos;
--  SHOW TABLES LIKE 'versoes';
-- =====================================================================
