-- Corrige links de notificações antigas gravadas com caminho errado
-- Execute este script UMA VEZ no phpMyAdmin se você já tinha notificações no banco

UPDATE notificacoes 
SET link = REPLACE(link, '/app/adm.php', '/app/painel/adm.php')
WHERE link LIKE '%/app/adm.php%' AND link NOT LIKE '%/app/painel/adm.php%';

UPDATE notificacoes 
SET link = REPLACE(link, 'adm.php#manifest-', '/projeto_final/app/painel/adm.php#manifest-')
WHERE link LIKE 'adm.php#manifest-%';
