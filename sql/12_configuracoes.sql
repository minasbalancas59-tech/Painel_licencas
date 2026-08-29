-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 12: configuracoes editaveis pelo painel
--
--  Aplicar depois da 11:
--      mysql licencas < sql/12_configuracoes.sql
--
--  Tira o e-mail do config.php e traz para a tela de Configuracoes.
--  O config.php continua com o que NAO pode mudar em runtime: acesso
--  ao banco e caminho das chaves.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configuracoes (
    chave         VARCHAR(60)  NOT NULL PRIMARY KEY,
    valor         TEXT         NULL,
    -- 1 = guardado cifrado (senhas). Ver api/lib/config_db.php:
    -- a chave de cifra vem de fora do banco, entao um dump vazado
    -- (o backup diario vai para a nuvem) nao entrega a senha.
    cifrado       TINYINT(1)   NOT NULL DEFAULT 0,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    atualizado_por INT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- valores iniciais (vazios; preenchidos pela tela de Configuracoes)
INSERT IGNORE INTO configuracoes (chave, valor, cifrado) VALUES
  ('email_admin',    NULL, 0),
  ('smtp_host',      NULL, 0),
  ('smtp_porta',     '587', 0),
  ('smtp_usuario',   NULL, 0),
  ('smtp_senha',     NULL, 1),
  ('smtp_de',        NULL, 0),
  ('smtp_de_nome',   'Painel de Licenças', 0),
  ('aviso_ativo',    '1', 0),
  ('aviso_marcos',   '30,15,7,0', 0),
  ('aviso_revendedor','1', 0);

-- =====================================================================
--  CONFERENCIA:
--  SELECT chave, cifrado, atualizado_em FROM configuracoes;
-- =====================================================================
