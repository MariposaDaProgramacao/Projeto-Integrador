-- ================================================================
-- ARQUIVO: backup_completo_sistema_cursos.sql
-- DATA: 2026-07-14
-- DESCRIÇÃO: Backup completo de todas as funções, procedures,
-- triggers, tabelas e eventos do sistema de cursos
-- ================================================================

-- ================================================================
-- 1. TABELA RECESSOS (com filtros para feriados e recessos)
-- ================================================================

-- Criar tabela recessos
CREATE TABLE recessos (
    id_recesso INT PRIMARY KEY AUTO_INCREMENT,
    nome_recesso VARCHAR(100) NOT NULL,
    descricao TEXT,
    data_inicio DATE NOT NULL,
    data_fim DATE NOT NULL,
    ano INT NOT NULL,
    tipo ENUM('feriado', 'recesso', 'ponto_facultativo', 'paralisacao') DEFAULT 'feriado',
    
    -- Filtros de aplicação (quando NULL = aplica a todos)
    id_unidade INT NULL,
    turno_curso ENUM('manha', 'tarde', 'noite', 'integral') NULL,
    tipo_curso ENUM('curso_agil', 'curso_tecnico', 'pos_graduacao', 'curso_livre') NULL,
    dias_semana SET('segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo') NULL,
    
    -- Controle
    ativo BOOLEAN DEFAULT TRUE,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Adicionar índices para performance
CREATE INDEX idx_datas ON recessos(data_inicio, data_fim);
CREATE INDEX idx_unidade ON recessos(id_unidade);
CREATE INDEX idx_ativo ON recessos(ativo);
CREATE INDEX idx_turno ON recessos(turno_curso);
CREATE INDEX idx_tipo ON recessos(tipo_curso);

-- ================================================================
-- 2. FUNÇÃO: CALCULAR DATA FIM COMPLETA
-- ================================================================
-- DESCRIÇÃO: Calcula a data de fim do curso considerando:
-- - Dias da semana que o curso tem aula
-- - Feriados e recessos cadastrados
-- - Filtros por unidade, turno, tipo de curso e dia da semana
-- ================================================================

DELIMITER //

CREATE FUNCTION calcular_data_fim_completa(
    p_data_inicio DATE,
    p_dias_letivos INT,
    p_dias_semana_curso VARCHAR(100),
    p_id_unidade INT,
    p_turno_curso VARCHAR(20),
    p_tipo_curso VARCHAR(30)
) 
RETURNS DATE
DETERMINISTIC
BEGIN
    DECLARE v_data_fim DATE;
    DECLARE v_dias_contados INT DEFAULT 0;
    DECLARE v_dia_semana INT;
    DECLARE v_data_atual DATE;
    DECLARE v_eh_recesso INT DEFAULT 0;
    DECLARE v_dia_semana_nome VARCHAR(20);
    
    SET v_data_atual = p_data_inicio;
    
    -- Enquanto não contar todos os dias letivos
    WHILE v_dias_contados < p_dias_letivos DO
        
        -- Verifica se a data atual é um recesso/feriado
        SELECT COUNT(*) INTO v_eh_recesso
        FROM recessos
        WHERE v_data_atual BETWEEN data_inicio AND data_fim
          AND ativo = 1
          AND (id_unidade = p_id_unidade OR id_unidade IS NULL)
          AND (turno_curso = p_turno_curso OR turno_curso IS NULL)
          AND (tipo_curso = p_tipo_curso OR tipo_curso IS NULL)
          AND (dias_semana IS NULL OR 
               FIND_IN_SET(
                   CASE DAYOFWEEK(v_data_atual)
                       WHEN 1 THEN 'domingo'
                       WHEN 2 THEN 'segunda'
                       WHEN 3 THEN 'terça'
                       WHEN 4 THEN 'quarta'
                       WHEN 5 THEN 'quinta'
                       WHEN 6 THEN 'sexta'
                       WHEN 7 THEN 'sábado'
                   END,
                   dias_semana
               ) > 0);
        
        -- Verifica se é dia de aula (baseado nos dias da semana do curso)
        SET v_dia_semana = DAYOFWEEK(v_data_atual);
        SET v_dia_semana_nome = CASE v_dia_semana
            WHEN 1 THEN 'domingo'
            WHEN 2 THEN 'segunda'
            WHEN 3 THEN 'terça'
            WHEN 4 THEN 'quarta'
            WHEN 5 THEN 'quinta'
            WHEN 6 THEN 'sexta'
            WHEN 7 THEN 'sábado'
        END;
        
        -- Se NÃO for recesso E for dia de aula, conta como dia letivo
        IF v_eh_recesso = 0 THEN
            IF FIND_IN_SET(v_dia_semana_nome, p_dias_semana_curso) > 0 THEN
                SET v_dias_contados = v_dias_contados + 1;
            END IF;
        END IF;
        
        -- Se ainda não completou, avança para o próximo dia
        IF v_dias_contados < p_dias_letivos THEN
            SET v_data_atual = DATE_ADD(v_data_atual, INTERVAL 1 DAY);
        END IF;
        
    END WHILE;
    
    SET v_data_fim = v_data_atual;
    
    RETURN v_data_fim;
END //

DELIMITER ;

-- ================================================================
-- 3. FUNÇÃO: CALCULAR PERCENTUAL DE CONCLUSÃO
-- ================================================================
-- DESCRIÇÃO: Calcula automaticamente o percentual de conclusão
-- do curso baseado em:
-- - Dias letivos totais
-- - Dias letivos passados (desde o início até hoje)
-- - Status do curso (concluído = 100%)
-- ================================================================

DELIMITER //

CREATE FUNCTION calcular_percentual_conclusao(
    p_data_inicio DATE,
    p_data_fim DATE,
    p_dias_letivos INT,
    p_status VARCHAR(20)
) 
RETURNS DECIMAL(5,2)
DETERMINISTIC
BEGIN
    DECLARE v_percentual DECIMAL(5,2) DEFAULT 0.00;
    DECLARE v_dias_passados INT DEFAULT 0;
    
    -- Se o curso já foi concluído, retorna 100%
    IF p_status = 'concluido' THEN
        RETURN 100.00;
    END IF;
    
    -- Se não tem data de início ou dias letivos, retorna 0
    IF p_data_inicio IS NULL OR p_dias_letivos IS NULL OR p_dias_letivos <= 0 THEN
        RETURN 0.00;
    END IF;
    
    -- Se a data de início for no futuro, retorna 0%
    IF CURDATE() < p_data_inicio THEN
        RETURN 0.00;
    END IF;
    
    -- Se a data atual for após o fim, retorna 100%
    IF p_data_fim IS NOT NULL AND CURDATE() > p_data_fim THEN
        RETURN 100.00;
    END IF;
    
    -- Calcula dias passados desde o início
    SET v_dias_passados = DATEDIFF(CURDATE(), p_data_inicio);
    
    -- Se já passou mais dias que o total, concluiu
    IF v_dias_passados >= p_dias_letivos THEN
        RETURN 100.00;
    END IF;
    
    -- Calcula porcentagem
    SET v_percentual = (v_dias_passados / p_dias_letivos) * 100;
    
    -- Arredonda para 2 casas decimais
    SET v_percentual = ROUND(v_percentual, 2);
    
    -- Limita entre 0 e 100
    IF v_percentual < 0 THEN
        SET v_percentual = 0.00;
    END IF;
    
    IF v_percentual > 100 THEN
        SET v_percentual = 100.00;
    END IF;
    
    RETURN v_percentual;
END //

DELIMITER ;

-- ================================================================
-- 4. PROCEDURE: ATUALIZAR DIAS LETIVOS E DATA FIM
-- ================================================================
-- DESCRIÇÃO: Atualiza dias_letivos e data_fim_curso_calculada
-- para todos os cursos com status = 'ativo'
-- ================================================================

DELIMITER //

CREATE OR REPLACE PROCEDURE atualizar_dias_letivos()
BEGIN
    UPDATE cursos 
    SET 
        dias_letivos = CEIL(carga_horaria_curso / horas_por_dia),
        data_fim_curso_calculada = calcular_data_fim_completa(
            data_inicio_curso,
            CEIL(carga_horaria_curso / horas_por_dia),
            dias_semana,
            id_unidade,
            turno_curso,
            tipo_curso
        )
    WHERE status_curso = 'ativo';
END //

DELIMITER ;

-- ================================================================
-- 5. PROCEDURE: ATUALIZAR PERCENTUAL DE CONCLUSÃO
-- ================================================================
-- DESCRIÇÃO: Atualiza o percentual_conclusao de TODOS os cursos
-- ================================================================

DELIMITER //

CREATE OR REPLACE PROCEDURE atualizar_percentual_cursos()
BEGIN
    UPDATE cursos 
    SET percentual_conclusao = calcular_percentual_conclusao(
        data_inicio_curso,
        data_fim_curso_calculada,
        dias_letivos,
        status_curso
    );
END //

DELIMITER ;

-- ================================================================
-- 6. PROCEDURE: ATUALIZAR TUDO (Dias Letivos + Data Fim + Percentual)
-- ================================================================
-- DESCRIÇÃO: Atualiza TODOS os cursos com todos os cálculos
-- ================================================================

DELIMITER //

CREATE OR REPLACE PROCEDURE atualizar_todos_cursos()
BEGIN
    -- 1. Atualizar dias letivos e data fim (todos os cursos)
    UPDATE cursos 
    SET 
        dias_letivos = CEIL(carga_horaria_curso / horas_por_dia),
        data_fim_curso_calculada = calcular_data_fim_completa(
            data_inicio_curso,
            CEIL(carga_horaria_curso / horas_por_dia),
            dias_semana,
            id_unidade,
            turno_curso,
            tipo_curso
        );
    
    -- 2. Atualizar percentual de conclusão (todos os cursos)
    UPDATE cursos 
    SET percentual_conclusao = calcular_percentual_conclusao(
        data_inicio_curso,
        data_fim_curso_calculada,
        dias_letivos,
        status_curso
    );
END //

DELIMITER ;

-- ================================================================
-- 7. TRIGGER: CALCULAR AO INSERIR (cadastrar novo curso)
-- ================================================================
-- DESCRIÇÃO: Quando um novo curso é cadastrado, calcula
-- automaticamente: dias_letivos, data_fim e percentual_conclusao
-- ================================================================

DELIMITER //

CREATE OR REPLACE TRIGGER calcular_dias_letivos_insert
BEFORE INSERT ON cursos
FOR EACH ROW
BEGIN
    -- Calcular dias letivos
    SET NEW.dias_letivos = CEIL(NEW.carga_horaria_curso / NEW.horas_por_dia);
    
    -- Calcular data fim
    SET NEW.data_fim_curso_calculada = calcular_data_fim_completa(
        NEW.data_inicio_curso,
        NEW.dias_letivos,
        NEW.dias_semana,
        NEW.id_unidade,
        NEW.turno_curso,
        NEW.tipo_curso
    );
    
    -- Calcular percentual de conclusão
    SET NEW.percentual_conclusao = calcular_percentual_conclusao(
        NEW.data_inicio_curso,
        NEW.data_fim_curso_calculada,
        NEW.dias_letivos,
        NEW.status_curso
    );
END //

DELIMITER ;

-- ================================================================
-- 8. TRIGGER: CALCULAR AO ATUALIZAR (editar curso)
-- ================================================================
-- DESCRIÇÃO: Quando um curso é editado, recalcula automaticamente
-- se os campos relevantes forem alterados
-- ================================================================

DELIMITER //

CREATE OR REPLACE TRIGGER calcular_dias_letivos_update
BEFORE UPDATE ON cursos
FOR EACH ROW
BEGIN
    -- Recalcula se alterar carga horária, horas/dia, data início, dias semana, unidade, turno ou tipo
    IF NEW.carga_horaria_curso != OLD.carga_horaria_curso 
       OR NEW.horas_por_dia != OLD.horas_por_dia 
       OR NEW.data_inicio_curso != OLD.data_inicio_curso
       OR NEW.dias_semana != OLD.dias_semana
       OR NEW.id_unidade != OLD.id_unidade
       OR NEW.turno_curso != OLD.turno_curso
       OR NEW.tipo_curso != OLD.tipo_curso THEN
        
        -- Calcular dias letivos
        SET NEW.dias_letivos = CEIL(NEW.carga_horaria_curso / NEW.horas_por_dia);
        
        -- Calcular data fim
        SET NEW.data_fim_curso_calculada = calcular_data_fim_completa(
            NEW.data_inicio_curso,
            NEW.dias_letivos,
            NEW.dias_semana,
            NEW.id_unidade,
            NEW.turno_curso,
            NEW.tipo_curso
        );
    END IF;
    
    -- Recalcula percentual se alterar data início, data fim, dias letivos ou status
    IF NEW.data_inicio_curso != OLD.data_inicio_curso 
       OR NEW.data_fim_curso_calculada != OLD.data_fim_curso_calculada
       OR NEW.dias_letivos != OLD.dias_letivos
       OR NEW.status_curso != OLD.status_curso THEN
        
        SET NEW.percentual_conclusao = calcular_percentual_conclusao(
            NEW.data_inicio_curso,
            NEW.data_fim_curso_calculada,
            NEW.dias_letivos,
            NEW.status_curso
        );
    END IF;
END //

DELIMITER ;

-- ================================================================
-- 9. EVENTO: ATUALIZAR PERCENTUAL DIARIAMENTE (opcional)
-- ================================================================
-- DESCRIÇÃO: Executa automaticamente todo dia às 00:00 para
-- manter os percentuais atualizados
-- ================================================================

-- Habilitar scheduler (se necessário)
SET GLOBAL event_scheduler = ON;

-- Criar evento para atualizar percentuais todo dia às 00:00
DELIMITER //

CREATE EVENT IF NOT EXISTS atualizar_percentual_diario
ON SCHEDULE EVERY 1 DAY 
STARTS CURDATE() + INTERVAL 1 DAY
DO
BEGIN
    CALL atualizar_percentual_cursos();
END //

DELIMITER ;

-- ================================================================
-- 10. COMANDOS PARA VERIFICAR E CONSULTAR
-- ================================================================

-- Ver todos os cursos com dados calculados
SELECT 
    id_curso,
    numero_curso,
    nome_curso,
    carga_horaria_curso,
    horas_por_dia,
    dias_letivos,
    data_inicio_curso,
    data_fim_curso_calculada,
    percentual_conclusao,
    status_curso
FROM cursos 
ORDER BY id_curso;

-- Verificar triggers
SHOW TRIGGERS;

-- Verificar functions
SHOW FUNCTION STATUS WHERE Db = 'senac_salas';

-- Verificar procedures
SHOW PROCEDURE STATUS WHERE Db = 'senac_salas';

-- Verificar eventos
SHOW EVENTS;

-- ================================================================
-- 11. COMANDOS PARA ATUALIZAR MANUALMENTE
-- ================================================================

-- Atualizar apenas dias letivos e data fim (cursos ativos)
CALL atualizar_dias_letivos();

-- Atualizar apenas percentual de conclusão (todos os cursos)
CALL atualizar_percentual_cursos();

-- Atualizar TUDO (dias letivos + data fim + percentual)
CALL atualizar_todos_cursos();

-- ================================================================
-- 12. COMANDOS PARA DELETAR (se precisar recriar)
-- ================================================================

-- Remover triggers
DROP TRIGGER IF EXISTS calcular_dias_letivos_insert;
DROP TRIGGER IF EXISTS calcular_dias_letivos_update;

-- Remover functions
DROP FUNCTION IF EXISTS calcular_data_fim_completa;
DROP FUNCTION IF EXISTS calcular_percentual_conclusao;

-- Remover procedures
DROP PROCEDURE IF EXISTS atualizar_dias_letivos;
DROP PROCEDURE IF EXISTS atualizar_percentual_cursos;
DROP PROCEDURE IF EXISTS atualizar_todos_cursos;

-- Remover evento
DROP EVENT IF EXISTS atualizar_percentual_diario;

-- Remover tabela recessos (cuidado! perde os dados)
-- DROP TABLE IF EXISTS recessos;

-- ================================================================
-- 13. EXEMPLOS DE INSERÇÃO DE RECESSOS PARA TESTE
-- ================================================================

-- Exemplo 1: Feriado geral (afeta todos)
INSERT INTO recessos (
    nome_recesso, 
    data_inicio, 
    data_fim, 
    ano, 
    tipo
) VALUES (
    'Carnaval 2026',
    '2026-02-15',
    '2026-02-17',
    2026,
    'feriado'
);

-- Exemplo 2: Recesso só para cursos da manhã
INSERT INTO recessos (
    nome_recesso, 
    data_inicio, 
    data_fim, 
    ano, 
    tipo,
    turno_curso
) VALUES (
    'Recesso Manhã - Julho',
    '2026-07-20',
    '2026-07-24',
    2026,
    'recesso',
    'manha'
);

-- Exemplo 3: Feriado só para cursos técnicos
INSERT INTO recessos (
    nome_recesso, 
    data_inicio, 
    data_fim, 
    ano, 
    tipo,
    tipo_curso
) VALUES (
    'Dia do Técnico',
    '2026-08-15',
    '2026-08-15',
    2026,
    'feriado',
    'curso_tecnico'
);

-- Exemplo 4: Recesso só às sextas-feiras
INSERT INTO recessos (
    nome_recesso, 
    data_inicio, 
    data_fim, 
    ano, 
    tipo,
    dias_semana
) VALUES (
    'Sextas de Recesso',
    '2026-09-04',
    '2026-09-25',
    2026,
    'recesso',
    'sexta'
);

-- ================================================================
-- 14. EXEMPLOS DE INSERÇÃO DE CURSOS PARA TESTE
-- ================================================================

-- Exemplo 1: Curso técnico
INSERT INTO cursos (
    id_unidade, 
    numero_curso, 
    nome_curso, 
    carga_horaria_curso, 
    horas_por_dia, 
    data_inicio_curso, 
    turno_curso, 
    dias_semana, 
    tipo_curso,
    status_curso
) VALUES (
    1,
    'TEC-001-2026',
    'Curso Técnico Teste',
    120,
    4,
    '2026-07-20',
    'noite',
    'segunda,terça,quarta,quinta,sexta',
    'curso_tecnico',
    'ativo'
);

-- Exemplo 2: Curso ágil
INSERT INTO cursos (
    id_unidade, 
    numero_curso, 
    nome_curso, 
    carga_horaria_curso, 
    horas_por_dia, 
    data_inicio_curso, 
    turno_curso, 
    dias_semana, 
    tipo_curso,
    status_curso
) VALUES (
    2,
    'AGIL-001-2026',
    'Curso Ágil Teste',
    40,
    4,
    '2026-08-01',
    'manha',
    'segunda,terça,quarta,quinta,sexta',
    'curso_agil',
    'ativo'
);

-- ================================================================
-- ALTERAÇÕES NA TABELA cronograma
-- DATA: 2026-07-14
-- DESCRIÇÃO: Renomear campo nome_aula para dias_letivos e 
-- criar triggers para preenchimento automático
-- ================================================================

-- ================================================================
-- 1. REMOVER TRIGGERS ANTIGOS (se existirem)
-- ================================================================

DROP TRIGGER IF EXISTS cronograma_dias_letivos_insert;
DROP TRIGGER IF EXISTS cronograma_dias_letivos_update;
DROP PROCEDURE IF EXISTS atualizar_dias_letivos_cronograma;

-- ================================================================
-- 2. RENOMEAR CAMPO nome_aula PARA dias_letivos
-- ================================================================

ALTER TABLE cronograma 
CHANGE COLUMN nome_aula dias_letivos INT;

-- ================================================================
-- 3. CRIAR PROCEDURE AUXILIAR (opcional, para debug)
-- ================================================================

DELIMITER //

CREATE PROCEDURE atualizar_dias_letivos_cronograma(
    p_id_aula INT,
    p_id_curso INT
)
BEGIN
    DECLARE v_dias_letivos INT;
    
    SELECT dias_letivos INTO v_dias_letivos
    FROM cursos
    WHERE id_curso = p_id_curso;
    
    UPDATE cronograma 
    SET dias_letivos = v_dias_letivos
    WHERE id_aula = p_id_aula;
END //

DELIMITER ;

-- ================================================================
-- 4. CRIAR TRIGGER INSERT
-- ================================================================

DELIMITER //

CREATE TRIGGER cronograma_dias_letivos_insert
BEFORE INSERT ON cronograma
FOR EACH ROW
BEGIN
    DECLARE v_dias_letivos INT DEFAULT 0;
    
    -- Busca o dias_letivos da tabela cursos
    SELECT IFNULL(dias_letivos, 0) INTO v_dias_letivos
    FROM cursos
    WHERE id_curso = NEW.id_curso;
    
    -- Define o valor
    SET NEW.dias_letivos = v_dias_letivos;
END //

DELIMITER ;

-- ================================================================
-- 5. CRIAR TRIGGER UPDATE
-- ================================================================

DELIMITER //

CREATE TRIGGER cronograma_dias_letivos_update
BEFORE UPDATE ON cronograma
FOR EACH ROW
BEGIN
    DECLARE v_dias_letivos INT DEFAULT 0;
    
    -- Se o curso for alterado, atualiza o dias_letivos
    IF NEW.id_curso != OLD.id_curso THEN
        SELECT IFNULL(dias_letivos, 0) INTO v_dias_letivos
        FROM cursos
        WHERE id_curso = NEW.id_curso;
        
        SET NEW.dias_letivos = v_dias_letivos;
    END IF;
END //

DELIMITER ;

-- ================================================================
-- 6. VERIFICAR SE OS TRIGGERS FORAM CRIADOS
-- ================================================================

SHOW TRIGGERS WHERE `Table` = 'cronograma';

-- ================================================================
-- 7. ATUALIZAR REGISTROS EXISTENTES (se houver dados)
-- ================================================================

UPDATE cronograma c
JOIN cursos cu ON c.id_curso = cu.id_curso
SET c.dias_letivos = cu.dias_letivos;

-- ================================================================
-- 8. TESTAR FUNCIONAMENTO (opcional)
-- ================================================================

-- Inserir uma aula de teste
INSERT INTO cronograma (
    id_sala,
    id_curso,
    data_aula,
    turno,
    horario_inicio,
    horario_fim,
    status_aula,
    observacao
) VALUES (
    2,
    1,
    '2026-07-20',
    'manha',
    '08:00:00',
    '12:00:00',
    'agendada',
    'Aula teste com trigger'
);

-- Verificar se o dias_letivos foi preenchido
SELECT 
    id_aula,
    id_curso,
    dias_letivos,
    data_aula,
    turno
FROM cronograma 
ORDER BY id_aula DESC 
LIMIT 1;

-- Remover o teste (se quiser)
-- DELETE FROM cronograma WHERE observacao = 'Aula teste com trigger';

-- ================================================================
-- 9. COMANDOS PARA DESFAZER (se precisar)
-- ================================================================

-- DROP TRIGGER IF EXISTS cronograma_dias_letivos_insert;
-- DROP TRIGGER IF EXISTS cronograma_dias_letivos_update;
-- DROP PROCEDURE IF EXISTS atualizar_dias_letivos_cronograma;
-- ALTER TABLE cronograma CHANGE COLUMN dias_letivos nome_aula VARCHAR(100);

-- ================================================================
-- FIM DO SCRIPT
-- ================================================================


-- ================================================================
-- 15. COMANDO PARA LIMPAR DADOS DE TESTE
-- ================================================================

-- Remover cursos de teste
-- DELETE FROM cursos WHERE numero_curso LIKE '%TESTE%';

-- Remover recessos de teste
-- DELETE FROM recessos WHERE nome_recesso LIKE '%Teste%' OR nome_recesso LIKE '%Carnaval%';

-- ================================================================
-- FIM DO ARQUIVO
-- ================================================================