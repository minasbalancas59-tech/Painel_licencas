-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 22: nivel Completa no TS6
--
--  Aplicar depois da 21:
--      mysql licencas < sql/22_ts6_completa.sql
--
--  NIVEIS DO TS6 (cumulativos - nivel 4 tem tudo dos niveis 1 a 4)
--
--    1 light      cadastros, consultas e relatorios. NAO PESA.
--    2 basico     + pesagem
--    3 cameras    + cameras
--    4 automacao  + automacao completa
--    5 completa   tudo, sem restricao
--
--  MODULOS FICARAM DE FORA
--  Protheus e Navio nao entram como modulos licenciaveis. A decisao
--  foi controlar tudo por nivel; se um dia isso mudar, basta cadastrar
--  em Catalogo > Modulos, sem alterar codigo.
--
--  NAO MEXE EM LICENCA EXISTENTE
--  So insere o tier novo e preenche descricoes vazias. Nenhum ALTER,
--  nenhum UPDATE em licencas. As emitidas continuam apontando para o
--  tier que sempre tiveram.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  nivel Completa, so no TS6
-- ---------------------------------------------------------------------
INSERT INTO tiers (produto_id, codigo, nome, descricao, nivel, ativo)
SELECT p.id, 'completa', 'Completa',
       'Todos os recursos do sistema, sem restricao', 5, 1
  FROM produtos p
 WHERE p.codigo = 'ts6'
   AND NOT EXISTS (
        SELECT 1 FROM tiers t
         WHERE t.produto_id = p.id AND t.codigo = 'completa');

-- ---------------------------------------------------------------------
--  descricoes dos niveis, para aparecerem no Catalogo e na emissao
-- ---------------------------------------------------------------------
UPDATE tiers t JOIN produtos p ON p.id = t.produto_id
   SET t.descricao = 'Cadastros, consultas e relatorios. Nao finaliza pesagens.'
 WHERE p.codigo = 'ts6' AND t.codigo = 'light'
   AND (t.descricao IS NULL OR t.descricao = '');

UPDATE tiers t JOIN produtos p ON p.id = t.produto_id
   SET t.descricao = 'Pesagem completa.'
 WHERE p.codigo = 'ts6' AND t.codigo = 'basico'
   AND (t.descricao IS NULL OR t.descricao = '');

UPDATE tiers t JOIN produtos p ON p.id = t.produto_id
   SET t.descricao = 'Pesagem + cameras.'
 WHERE p.codigo = 'ts6' AND t.codigo = 'cameras'
   AND (t.descricao IS NULL OR t.descricao = '');

UPDATE tiers t JOIN produtos p ON p.id = t.produto_id
   SET t.descricao = 'Pesagem + cameras + automacao completa.'
 WHERE p.codigo = 'ts6' AND t.codigo = 'automacao'
   AND (t.descricao IS NULL OR t.descricao = '');

-- =====================================================================
--  CONFERENCIA:
--  SELECT t.nivel, t.codigo, t.nome, t.descricao
--    FROM tiers t JOIN produtos p ON p.id=t.produto_id
--   WHERE p.codigo='ts6' ORDER BY t.nivel;
-- =====================================================================
