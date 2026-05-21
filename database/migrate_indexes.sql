-- ============================================================
-- MIGRAÇÃO: Adiciona índices de performance ao banco existente
-- Execute este script UMA VEZ em bancos já criados pelo schema.sql original.
-- Usa DROP + ADD para ser idempotente — seguro rodar mais de uma vez.
-- ============================================================

USE `dbouvidoria`;

-- Índice em status (consultas SUM(status=...) no dashboard e index)
ALTER TABLE `tbmanifest`
  DROP KEY IF EXISTS `idx_manifest_status`,
  ADD KEY `idx_manifest_status` (`status`);

-- Índice em criado_em (filtro de período no dashboard)
ALTER TABLE `tbmanifest`
  DROP KEY IF EXISTS `idx_manifest_criado`,
  ADD KEY `idx_manifest_criado` (`criado_em`);

-- Índice em arquivada (filtro padrão no painel adm)
ALTER TABLE `tbmanifest`
  DROP KEY IF EXISTS `idx_manifest_arquivada`,
  ADD KEY `idx_manifest_arquivada` (`arquivada`);
