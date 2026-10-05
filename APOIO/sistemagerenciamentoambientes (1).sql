-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 03/09/2026 às 14:41
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `sistemagerenciamentoambientes`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id_cliente` int(11) NOT NULL,
  `nome_cliente` varchar(100) NOT NULL,
  `tipo_cliente` enum('escola','salão','empresa','outro') NOT NULL,
  `cnpj_cliente` varchar(18) DEFAULT NULL,
  `email_cliente` varchar(100) NOT NULL,
  `telefone_cliente` varchar(15) DEFAULT NULL,
  `endereco_cliente` varchar(200) DEFAULT NULL,
  `cidade_cliente` varchar(80) DEFAULT NULL,
  `estado_cliente` char(2) DEFAULT NULL,
  `status_cliente` enum('ativo','inativo','pendente','bloqueado') NOT NULL DEFAULT 'pendente',
  `plano_cliente` enum('gratuito','basico','profissional','enterprise') NOT NULL DEFAULT 'gratuito',
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `data_vencimento` date DEFAULT NULL,
  `limite_unidades` int(11) DEFAULT 1,
  `limite_usuarios` int(11) DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `clientes`
--

INSERT INTO `clientes` (`id_cliente`, `nome_cliente`, `tipo_cliente`, `cnpj_cliente`, `email_cliente`, `telefone_cliente`, `endereco_cliente`, `cidade_cliente`, `estado_cliente`, `status_cliente`, `plano_cliente`, `data_cadastro`, `data_vencimento`, `limite_unidades`, `limite_usuarios`) VALUES
(1, 'Cliente Padrão', 'outro', NULL, 'padrao@cliente.com', NULL, NULL, NULL, NULL, 'ativo', 'gratuito', '2026-09-02 10:05:43', NULL, 1, 5);

-- --------------------------------------------------------

--
-- Estrutura para tabela `configuracoes_turnos`
--

CREATE TABLE `configuracoes_turnos` (
  `id_config` int(11) NOT NULL,
  `turno` varchar(10) NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `intervalo_inicio` time DEFAULT NULL,
  `intervalo_fim` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `configuracoes_turnos`
--

INSERT INTO `configuracoes_turnos` (`id_config`, `turno`, `horario_inicio`, `horario_fim`, `intervalo_inicio`, `intervalo_fim`) VALUES
(1, 'manha', '08:00:00', '12:00:00', '10:00:00', '10:15:00'),
(2, 'tarde', '13:00:00', '17:00:00', '15:00:00', '15:15:00'),
(3, 'noite', '19:00:00', '22:30:00', '20:30:00', '20:45:00');

-- --------------------------------------------------------

--
-- Estrutura para tabela `cronograma`
--

CREATE TABLE `cronograma` (
  `id_aula` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `id_curso` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `id_professor` int(11) DEFAULT 0,
  `dias_letivos` int(11) DEFAULT NULL,
  `data_aula` date NOT NULL,
  `turno` enum('manha','tarde','noite') NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `status_aula` enum('agendada','realizada','cancelada','remarcada','aguardando_remarcacao') NOT NULL DEFAULT 'agendada',
  `observacao` text DEFAULT NULL,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `cronograma`
--

INSERT INTO `cronograma` (`id_aula`, `id_sala`, `id_curso`, `id_unidade`, `id_professor`, `dias_letivos`, `data_aula`, `turno`, `horario_inicio`, `horario_fim`, `status_aula`, `observacao`, `id_cliente`) VALUES
(2006, 3, 1, 1, 2, 1, '2026-09-01', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 1 - Introdução ao HTML/CSS', 1),
(2007, 3, 1, 1, 2, 2, '2026-09-02', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 2 - JavaScript Básico', 1),
(2008, 3, 1, 1, 2, 3, '2026-09-03', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 3 - Frameworks Front-end', 1),
(2009, 3, 1, 1, 2, 4, '2026-09-04', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 4 - Back-end com Node.js', 1),
(2010, 3, 1, 1, 2, 5, '2026-09-07', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 5 - Banco de Dados', 1),
(2011, 4, 2, 1, 3, 1, '2026-09-01', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 1 - Introdução à Análise de Sistemas', 1),
(2012, 4, 2, 1, 3, 2, '2026-09-02', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 2 - Engenharia de Software', 1),
(2013, 4, 2, 1, 3, 3, '2026-09-03', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 3 - UML e Modelagem', 1),
(2014, 4, 2, 1, 3, 4, '2026-09-04', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 4 - Metodologias Ágeis', 1),
(2015, 4, 2, 1, 3, 5, '2026-09-07', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 5 - Gestão de Projetos', 1),
(2016, 7, 3, 1, 4, 1, '2026-09-05', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 1 - Fundamentos de IA', 1),
(2017, 7, 3, 1, 4, 2, '2026-09-07', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 2 - Machine Learning', 1),
(2018, 7, 3, 1, 4, 3, '2026-09-09', 'noite', '19:00:00', '22:30:00', 'agendada', 'Aula 3 - Deep Learning', 1),
(2019, 5, 4, 1, 2, 1, '2026-09-10', '', '08:00:00', '17:00:00', 'agendada', 'Aula 1 - Fundamentos Scrum', 1),
(2020, 5, 4, 1, 2, 2, '2026-09-11', '', '08:00:00', '17:00:00', 'agendada', 'Aula 2 - Papéis e Cerimônias', 1),
(2021, 5, 4, 1, 2, 3, '2026-09-14', '', '08:00:00', '17:00:00', 'agendada', 'Aula 3 - Planejamento e Estimativas', 1),
(2022, 5, 4, 1, 2, 4, '2026-09-15', '', '08:00:00', '17:00:00', 'agendada', 'Aula 4 - Práticas Avançadas', 1),
(2023, 6, 5, 1, 4, 1, '2026-09-01', 'tarde', '13:00:00', '17:00:00', 'agendada', 'Aula 1 - Conceitos de Redes', 1),
(2024, 6, 5, 1, 4, 2, '2026-09-02', 'tarde', '13:00:00', '17:00:00', 'agendada', 'Aula 2 - Modelo OSI', 1),
(2025, 6, 5, 1, 4, 3, '2026-09-03', 'tarde', '13:00:00', '17:00:00', 'agendada', 'Aula 3 - Protocolos TCP/IP', 1),
(2026, 6, 5, 1, 4, 4, '2026-09-04', 'tarde', '13:00:00', '17:00:00', 'agendada', 'Aula 4 - Roteamento', 1),
(2027, 3, 6, 1, 3, 1, '2026-09-12', '', '08:00:00', '17:00:00', 'agendada', 'Aula 1 - Introdução ao DevOps', 1),
(2028, 3, 6, 1, 3, 2, '2026-09-13', '', '08:00:00', '17:00:00', 'agendada', 'Aula 2 - CI/CD', 1),
(2029, 3, 6, 1, 3, 3, '2026-09-16', '', '08:00:00', '17:00:00', 'agendada', 'Aula 3 - Containers e Docker', 1),
(2030, 3, 6, 1, 3, 4, '2026-09-17', '', '08:00:00', '17:00:00', 'agendada', 'Aula 4 - Orquestração', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `cursos`
--

CREATE TABLE `cursos` (
  `id_curso` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `id_docente` int(11) DEFAULT NULL,
  `numero_curso` varchar(20) NOT NULL,
  `nome_curso` varchar(100) NOT NULL,
  `carga_horaria_curso` int(11) NOT NULL,
  `horas_por_dia` int(11) NOT NULL DEFAULT 4,
  `tipo_sala_preferencial` varchar(100) DEFAULT NULL,
  `data_inicio_curso` date NOT NULL,
  `data_fim_curso_calculada` date DEFAULT NULL,
  `dias_letivos` int(11) DEFAULT NULL,
  `turno_curso` enum('manha','tarde','noite','integral') NOT NULL,
  `dias_semana` set('segunda','terca','quarta','quinta','sexta','sabado') NOT NULL,
  `tipo_curso` enum('curso_agil','curso_tecnico','pos_graduacao') NOT NULL,
  `status_curso` enum('ativo','inativo','concluido') NOT NULL DEFAULT 'ativo',
  `percentual_conclusao` decimal(5,2) DEFAULT 0.00,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `cursos`
--

INSERT INTO `cursos` (`id_curso`, `id_unidade`, `id_docente`, `numero_curso`, `nome_curso`, `carga_horaria_curso`, `horas_por_dia`, `tipo_sala_preferencial`, `data_inicio_curso`, `data_fim_curso_calculada`, `dias_letivos`, `turno_curso`, `dias_semana`, `tipo_curso`, `status_curso`, `percentual_conclusao`, `id_cliente`) VALUES
(30, 1, 2, 'TEC001', 'Desenvolvimento Web Full Stack', 400, 4, 'laboratorio', '2026-09-01', '2026-12-20', 100, 'noite', 'segunda,terca,quarta,quinta,sexta', 'curso_tecnico', 'ativo', 0.00, 1),
(31, 1, 3, 'TEC002', 'Análise e Desenvolvimento de Sistemas', 360, 4, 'laboratorio', '2026-09-01', '2026-12-15', 90, 'noite', 'segunda,terca,quarta,quinta,sexta', 'curso_tecnico', 'ativo', 0.00, 1),
(32, 1, 4, 'POS001', 'Pós-Graduação em IA e Machine Learning', 240, 4, 'sala_aula', '2026-09-05', '2026-12-10', 60, 'noite', 'segunda,quarta,sexta', 'pos_graduacao', 'ativo', 0.00, 1),
(33, 1, 2, 'AGIL001', 'Scrum Master Avançado', 40, 8, 'sala_aula', '2026-09-10', '2026-09-15', 5, 'integral', 'segunda,terca,quarta,quinta,sexta', 'curso_agil', 'ativo', 0.00, 1),
(34, 1, 4, 'TEC003', 'Redes de Computadores', 320, 4, 'laboratorio', '2026-09-01', '2026-12-20', 80, 'tarde', 'segunda,terca,quarta,quinta,sexta', 'curso_tecnico', 'ativo', 0.00, 1),
(35, 1, 3, 'AGIL002', 'DevOps Essentials', 40, 8, 'laboratorio', '2026-09-12', '2026-09-17', 5, 'integral', 'segunda,terca,quarta,quinta,sexta', 'curso_agil', 'ativo', 0.00, 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `funcionarios`
--

CREATE TABLE `funcionarios` (
  `id_funcionario` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_funcionario` varchar(100) NOT NULL,
  `cargo_funcionario` enum('administrador','coordenador','professor','auxiliar','gerente','secretaria','portaria') NOT NULL,
  `id_unidade` int(11) DEFAULT NULL,
  `email_funcionario` varchar(100) NOT NULL,
  `telefone_funcionario` varchar(15) DEFAULT NULL,
  `senha_funcionario` varchar(255) NOT NULL,
  `status_acesso` enum('ativo','inativo','bloqueado') NOT NULL DEFAULT 'inativo',
  `data_ultimo_acesso` datetime DEFAULT NULL,
  `tentativas_login` int(11) NOT NULL DEFAULT 0,
  `data_cadastro_funcionario` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `funcionarios`
--

INSERT INTO `funcionarios` (`id_funcionario`, `id_cliente`, `nome_funcionario`, `cargo_funcionario`, `id_unidade`, `email_funcionario`, `telefone_funcionario`, `senha_funcionario`, `status_acesso`, `data_ultimo_acesso`, `tentativas_login`, `data_cadastro_funcionario`) VALUES
(1, 1, 'Administrador Sistema', 'administrador', NULL, 'admin@senac.br', '(31) 99999-9999', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', '2026-09-02 09:54:51', 0, '2026-06-30 20:44:48'),
(8, 1, 'João Silva', 'professor', 1, 'joao.silva@senac.br', '(31) 98888-1111', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(9, 1, 'Maria Santos', 'professor', 1, 'maria.santos@senac.br', '(31) 97777-2222', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(10, 1, 'Carlos Oliveira', 'coordenador', 1, 'carlos.oliveira@senac.br', '(31) 96666-3333', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(11, 1, 'Ana Pereira', 'professor', 1, 'ana.pereira@senac.br', '(31) 95555-4444', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(12, 1, 'Roberto Costa', 'secretaria', 1, 'roberto.costa@senac.br', '(31) 94444-5555', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(13, 1, 'Patrícia Lima', 'auxiliar', 1, 'patricia.lima@senac.br', '(31) 93333-6666', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(14, 1, 'Fernando Rocha', 'gerente', 1, 'fernando.rocha@senac.br', '(31) 92222-7777', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', NULL, 0, '2026-09-02 09:51:24'),
(15, 1, 'Shanaya Nataly', 'coordenador', 3, 'shaya@gmail.com', '31999999999', '$2y$10$o.RhwKVqqXAMQZz0smtug.3Z4f2D4F5KUFSQEXoxA2aq6Z4CAaBIC', 'ativo', '2026-09-02 09:55:12', 0, '2026-09-02 09:51:42');

-- --------------------------------------------------------

--
-- Estrutura para tabela `historico_sistema`
--

CREATE TABLE `historico_sistema` (
  `id_historico` int(11) NOT NULL,
  `id_funcionario` int(11) NOT NULL,
  `tabela_afetada` varchar(50) NOT NULL,
  `id_registro_afetado` int(11) NOT NULL,
  `acao` varchar(20) NOT NULL,
  `dados_anteriores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dados_novos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `data_acao` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_origem` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `historico_sistema`
--

INSERT INTO `historico_sistema` (`id_historico`, `id_funcionario`, `tabela_afetada`, `id_registro_afetado`, `acao`, `dados_anteriores`, `dados_novos`, `motivo`, `data_acao`, `ip_origem`) VALUES
(10, 1, 'cursos', 1, 'INSERT', NULL, 'Curso TEC001 criado', 'Criação do curso Desenvolvimento Web', '2026-09-02 09:51:24', '192.168.1.100'),
(11, 1, 'salas', 1, 'INSERT', NULL, 'Sala 101 cadastrada', 'Cadastro de sala de aula', '2026-09-02 09:51:24', '192.168.1.100'),
(12, 1, 'funcionarios', 2, 'INSERT', NULL, 'João Silva cadastrado como professor', 'Cadastro de novo professor', '2026-09-02 09:51:24', '192.168.1.100'),
(13, 1, 'cronograma', 1, 'INSERT', NULL, 'Aula 1 - TEC001 agendada', 'Agendamento de aula', '2026-09-02 09:51:24', '192.168.1.100'),
(14, 1, 'funcionarios', 15, 'INSERT', NULL, NULL, 'Cadastro de novo usuário: Shanaya Nataly', '2026-09-02 09:51:42', '::1'),
(15, 15, 'funcionarios', 15, 'UPDATE', NULL, NULL, 'Alteração de senha', '2026-09-02 09:55:30', '::1'),
(16, 1, 'usuarios_sistema', 1, 'login', NULL, '{\"usuario\":\"Administrador Sistema\",\"email\":\"admin@senac.br\",\"cliente\":\"Cliente Padr\\u00e3o\"}', NULL, '2026-09-03 09:26:18', '::1'),
(17, 1, 'usuarios_sistema', 1, 'login', NULL, '{\"usuario\":\"Administrador Sistema\",\"email\":\"admin@senac.br\",\"cliente\":\"Cliente Padr\\u00e3o\"}', NULL, '2026-09-03 09:26:29', '::1'),
(18, 1, 'usuarios_sistema', 1, 'login', NULL, '{\"usuario\":\"Administrador Sistema\",\"email\":\"admin@senac.br\",\"cliente\":\"Cliente Padr\\u00e3o\"}', NULL, '2026-09-03 09:26:45', '::1');

-- --------------------------------------------------------

--
-- Estrutura para tabela `manutencoes`
--

CREATE TABLE `manutencoes` (
  `id_manutencao` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `turno` enum('manha','tarde','noite','integral') NOT NULL,
  `motivo` varchar(200) NOT NULL,
  `status` enum('agendada','em_andamento','concluida') NOT NULL DEFAULT 'agendada',
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `manutencoes`
--

INSERT INTO `manutencoes` (`id_manutencao`, `id_sala`, `data_inicio`, `data_fim`, `turno`, `motivo`, `status`, `id_cliente`) VALUES
(12, 9, '2026-09-01', '2026-09-10', 'integral', 'Manutenção preventiva - elétrica e climatização', 'em_andamento', 1),
(13, 2, '2026-09-20', '2026-09-22', 'integral', 'Troca de projetor e manutenção de ar-condicionado', 'agendada', 1),
(14, 6, '2026-09-15', '2026-09-16', 'noite', 'Atualização dos computadores e instalação de softwares', 'agendada', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `progresso_curso`
--

CREATE TABLE `progresso_curso` (
  `id_progresso` int(11) NOT NULL,
  `id_curso` int(11) NOT NULL,
  `data_registro` date NOT NULL,
  `aulas_realizadas` int(11) NOT NULL DEFAULT 0,
  `aulas_previstas` int(11) NOT NULL,
  `percentual_conclusao` decimal(5,2) GENERATED ALWAYS AS (`aulas_realizadas` / nullif(`aulas_previstas`,0) * 100) STORED,
  `observacao` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `progresso_curso`
--

INSERT INTO `progresso_curso` (`id_progresso`, `id_curso`, `data_registro`, `aulas_realizadas`, `aulas_previstas`, `observacao`) VALUES
(6, 1, '2026-09-02', 1, 100, 'Curso iniciado com boa participação'),
(7, 2, '2026-09-02', 1, 90, 'Primeira semana de aula'),
(8, 3, '2026-09-05', 0, 60, 'Aguardando início das aulas'),
(9, 4, '2026-09-10', 0, 5, 'Curso agendado para iniciar'),
(10, 5, '2026-09-02', 1, 80, 'Curso de Redes iniciado'),
(11, 6, '2026-09-12', 0, 5, 'Aguardando início');

-- --------------------------------------------------------

--
-- Estrutura para tabela `recessos`
--

CREATE TABLE `recessos` (
  `id_recesso` int(11) NOT NULL,
  `nome_recesso` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `ano` int(11) NOT NULL,
  `tipo` enum('feriado','recesso','ponto_facultativo','paralisacao') DEFAULT 'feriado',
  `id_unidade` int(11) DEFAULT NULL,
  `turno_curso` enum('manha','tarde','noite','integral') DEFAULT NULL,
  `tipo_curso` enum('curso_agil','curso_tecnico','pos_graduacao','curso_livre') DEFAULT NULL,
  `dias_semana` set('segunda','terça','quarta','quinta','sexta','sábado','domingo') DEFAULT NULL,
  `id_cursos` text DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `recessos`
--

INSERT INTO `recessos` (`id_recesso`, `nome_recesso`, `descricao`, `data_inicio`, `data_fim`, `ano`, `tipo`, `id_unidade`, `turno_curso`, `tipo_curso`, `dias_semana`, `id_cursos`, `ativo`, `data_criacao`, `data_atualizacao`, `id_cliente`) VALUES
(21, 'Dia da Independência', 'Feriado nacional', '2026-09-07', '2026-09-07', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(22, 'Nossa Senhora Aparecida', 'Feriado nacional', '2026-10-12', '2026-10-12', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(23, 'Dia de Finados', 'Feriado nacional', '2026-11-02', '2026-11-02', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(24, 'Proclamação da República', 'Feriado nacional', '2026-11-15', '2026-11-15', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(25, 'Consciência Negra', 'Feriado estadual', '2026-11-20', '2026-11-20', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(26, 'Natal', 'Feriado nacional', '2026-12-25', '2026-12-25', 2026, 'feriado', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1),
(27, 'Recesso de Final de Ano', 'Período de recesso institucional', '2026-12-20', '2027-01-05', 2026, 'recesso', 1, NULL, NULL, NULL, NULL, 1, '2026-09-02 12:51:24', '2026-09-02 12:51:24', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `reservas`
--

CREATE TABLE `reservas` (
  `id_reserva` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `id_admin` int(11) NOT NULL,
  `titulo_reserva` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_reserva` date NOT NULL,
  `turno` enum('manha','tarde','noite') NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `status_reserva` enum('ativa','cancelada','concluida') NOT NULL DEFAULT 'ativa',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `reservas`
--

INSERT INTO `reservas` (`id_reserva`, `id_sala`, `id_admin`, `titulo_reserva`, `descricao`, `data_reserva`, `turno`, `horario_inicio`, `horario_fim`, `status_reserva`, `data_criacao`, `id_cliente`) VALUES
(1, 8, 1, 'Reunião de Coordenação', 'Reunião com coordenadores para alinhamento do semestre', '2026-09-15', 'manha', '09:00:00', '11:00:00', 'ativa', '2026-09-02 09:51:24', 1),
(2, 8, 1, 'Entrevistas Seleção', 'Entrevistas para novos professores', '2026-09-20', 'tarde', '14:00:00', '17:00:00', 'ativa', '2026-09-02 09:51:24', 1),
(3, 1, 1, 'Evento de Abertura', 'Cerimônia de abertura do semestre letivo', '2026-09-01', 'noite', '19:30:00', '22:00:00', 'concluida', '2026-09-02 09:51:24', 1),
(4, 5, 1, 'Treinamento Equipe', 'Treinamento de novos colaboradores', '2026-09-25', 'manha', '08:00:00', '12:00:00', 'ativa', '2026-09-02 09:51:24', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `salas`
--

CREATE TABLE `salas` (
  `id_sala` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `andar_sala` int(11) NOT NULL,
  `numero_sala` int(11) NOT NULL,
  `tipo_sala` varchar(100) NOT NULL DEFAULT 'sala_aula',
  `capacidade_sala` int(11) NOT NULL DEFAULT 30,
  `recursos_sala` text DEFAULT NULL,
  `status_sala` enum('disponivel','ocupada','manutencao','inativa') NOT NULL DEFAULT 'disponivel',
  `descricao_sala` varchar(500) DEFAULT NULL,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `salas`
--

INSERT INTO `salas` (`id_sala`, `id_unidade`, `andar_sala`, `numero_sala`, `tipo_sala`, `capacidade_sala`, `recursos_sala`, `status_sala`, `descricao_sala`, `id_cliente`) VALUES
(46, 1, 1, 101, 'sala_aula', 30, 'Projetor, Ar-condicionado, Quadro Branco', 'disponivel', 'Sala de aula principal - Térreo', 1),
(47, 1, 1, 102, 'sala_aula', 25, 'Projetor, Ar-condicionado, Quadro Branco', 'disponivel', 'Sala de aula - Térreo', 1),
(48, 1, 1, 103, 'laboratorio', 20, 'Computadores, Projetor, Ar-condicionado', 'disponivel', 'Laboratório de Informática', 1),
(49, 1, 2, 201, 'sala_aula', 35, 'Projetor, Ar-condicionado, Quadro Branco, TV', 'disponivel', 'Sala de aula - 2º Andar', 1),
(50, 1, 2, 202, 'sala_aula', 30, 'Projetor, Ar-condicionado, Quadro Branco', 'disponivel', 'Sala de aula - 2º Andar', 1),
(51, 1, 2, 203, 'laboratorio', 15, 'Computadores, Projetor, Ar-condicionado, Impressora', 'disponivel', 'Laboratório de Redes', 1),
(52, 1, 3, 301, 'sala_aula', 40, 'Projetor, Ar-condicionado, Quadro Branco, Som', 'disponivel', 'Auditório - 3º Andar', 1),
(53, 1, 3, 302, 'sala_reuniao', 12, 'TV, Ar-condicionado, Mesa Redonda', 'disponivel', 'Sala de Reuniões', 1),
(54, 1, 3, 303, 'sala_aula', 25, 'Projetor, Ar-condicionado', 'manutencao', 'Sala em manutenção', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `unidades`
--

CREATE TABLE `unidades` (
  `id_unidade` int(11) NOT NULL,
  `nome_unidade` varchar(100) NOT NULL,
  `estado_unidade` char(2) NOT NULL,
  `cidade_unidade` varchar(80) NOT NULL,
  `endereco_unidade` varchar(200) NOT NULL,
  `telefone_unidade` varchar(15) DEFAULT NULL,
  `email_unidade` varchar(100) DEFAULT NULL,
  `status_unidade` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `fuso` enum('America/Noronha','America/Belem','America/Fortaleza','America/Recife','America/Araguaina','America/Maceio','America/Bahia','America/Sao_Paulo','America/Campo_Grande','America/Cuiaba','America/Santarem','America/Porto_Velho','America/Boa_Vista','America/Manaus','America/Eirunepe','America/Rio_Branco') DEFAULT 'America/Sao_Paulo',
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `unidades`
--

INSERT INTO `unidades` (`id_unidade`, `nome_unidade`, `estado_unidade`, `cidade_unidade`, `endereco_unidade`, `telefone_unidade`, `email_unidade`, `status_unidade`, `fuso`, `id_cliente`) VALUES
(3, 'Unidade para testes do sistema', 'MG', 'Belo Horizonte', 'Rua do Encanto Esquina Virada da Felicidade', '31999999999', 'unidade@gmail.com', 'ativo', 'America/Sao_Paulo', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `unidades_nova`
--

CREATE TABLE `unidades_nova` (
  `id_unidade` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_unidade` varchar(100) NOT NULL,
  `estado_unidade` char(2) NOT NULL,
  `cidade_unidade` varchar(80) NOT NULL,
  `endereco_unidade` varchar(200) NOT NULL,
  `telefone_unidade` varchar(15) DEFAULT NULL,
  `email_unidade` varchar(100) DEFAULT NULL,
  `status_unidade` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `fuso` enum('America/Noronha','America/Belem','America/Fortaleza','America/Recife','America/Araguaina','America/Maceio','America/Bahia','America/Sao_Paulo','America/Campo_Grande','America/Cuiaba','America/Santarem','America/Porto_Velho','America/Boa_Vista','America/Manaus','America/Eirunepe','America/Rio_Branco') DEFAULT 'America/Sao_Paulo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios_sistema`
--

CREATE TABLE `usuarios_sistema` (
  `id_usuario` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_usuario` varchar(100) NOT NULL,
  `email_usuario` varchar(100) NOT NULL,
  `senha_usuario` varchar(255) NOT NULL,
  `tipo_usuario` enum('admin_cliente','gerente','usuario','visualizador') NOT NULL DEFAULT 'usuario',
  `status_usuario` enum('ativo','inativo','pendente','bloqueado') NOT NULL DEFAULT 'pendente',
  `telefone_usuario` varchar(15) DEFAULT NULL,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `data_ultimo_acesso` datetime DEFAULT NULL,
  `tentativas_login` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `usuarios_sistema`
--

INSERT INTO `usuarios_sistema` (`id_usuario`, `id_cliente`, `nome_usuario`, `email_usuario`, `senha_usuario`, `tipo_usuario`, `status_usuario`, `telefone_usuario`, `data_cadastro`, `data_ultimo_acesso`, `tentativas_login`) VALUES
(1, 1, 'Administrador Sistema', 'admin@senac.br', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'admin_cliente', 'ativo', '(31) 99999-9999', '2026-09-03 09:26:07', '2026-09-03 09:33:50', 0);

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id_cliente`),
  ADD UNIQUE KEY `email_cliente` (`email_cliente`),
  ADD UNIQUE KEY `cnpj_cliente` (`cnpj_cliente`);

--
-- Índices de tabela `configuracoes_turnos`
--
ALTER TABLE `configuracoes_turnos`
  ADD PRIMARY KEY (`id_config`),
  ADD UNIQUE KEY `turno` (`turno`);

--
-- Índices de tabela `cronograma`
--
ALTER TABLE `cronograma`
  ADD PRIMARY KEY (`id_aula`),
  ADD UNIQUE KEY `unique_sala_horario` (`id_sala`,`data_aula`,`horario_inicio`,`horario_fim`),
  ADD KEY `id_curso` (`id_curso`),
  ADD KEY `id_professor` (`id_professor`),
  ADD KEY `fk_cronograma_unidade` (`id_unidade`),
  ADD KEY `fk_cronograma_cliente` (`id_cliente`);

--
-- Índices de tabela `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`id_curso`),
  ADD UNIQUE KEY `numero_curso` (`numero_curso`),
  ADD KEY `id_unidade` (`id_unidade`),
  ADD KEY `id_docente` (`id_docente`),
  ADD KEY `fk_curso_cliente` (`id_cliente`);

--
-- Índices de tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  ADD PRIMARY KEY (`id_funcionario`),
  ADD UNIQUE KEY `email_funcionario` (`email_funcionario`),
  ADD KEY `id_unidade` (`id_unidade`),
  ADD KEY `fk_funcionario_cliente` (`id_cliente`);

--
-- Índices de tabela `historico_sistema`
--
ALTER TABLE `historico_sistema`
  ADD PRIMARY KEY (`id_historico`),
  ADD KEY `id_funcionario` (`id_funcionario`);

--
-- Índices de tabela `manutencoes`
--
ALTER TABLE `manutencoes`
  ADD PRIMARY KEY (`id_manutencao`),
  ADD KEY `id_sala` (`id_sala`),
  ADD KEY `fk_manutencao_cliente` (`id_cliente`);

--
-- Índices de tabela `progresso_curso`
--
ALTER TABLE `progresso_curso`
  ADD PRIMARY KEY (`id_progresso`),
  ADD KEY `id_curso` (`id_curso`);

--
-- Índices de tabela `recessos`
--
ALTER TABLE `recessos`
  ADD PRIMARY KEY (`id_recesso`),
  ADD KEY `idx_datas` (`data_inicio`,`data_fim`),
  ADD KEY `idx_unidade` (`id_unidade`),
  ADD KEY `idx_ativo` (`ativo`),
  ADD KEY `idx_turno` (`turno_curso`),
  ADD KEY `idx_tipo` (`tipo_curso`),
  ADD KEY `fk_recesso_cliente` (`id_cliente`);

--
-- Índices de tabela `reservas`
--
ALTER TABLE `reservas`
  ADD PRIMARY KEY (`id_reserva`),
  ADD UNIQUE KEY `unique_reserva_horario` (`id_sala`,`data_reserva`,`horario_inicio`,`horario_fim`,`status_reserva`),
  ADD KEY `id_admin` (`id_admin`),
  ADD KEY `fk_reserva_cliente` (`id_cliente`);

--
-- Índices de tabela `salas`
--
ALTER TABLE `salas`
  ADD PRIMARY KEY (`id_sala`),
  ADD UNIQUE KEY `id_unidade` (`id_unidade`,`numero_sala`),
  ADD KEY `fk_sala_cliente` (`id_cliente`);

--
-- Índices de tabela `unidades`
--
ALTER TABLE `unidades`
  ADD PRIMARY KEY (`id_unidade`),
  ADD UNIQUE KEY `nome_unidade` (`nome_unidade`),
  ADD KEY `fk_unidade_cliente` (`id_cliente`);

--
-- Índices de tabela `unidades_nova`
--
ALTER TABLE `unidades_nova`
  ADD PRIMARY KEY (`id_unidade`),
  ADD UNIQUE KEY `nome_unidade` (`nome_unidade`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Índices de tabela `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `email_usuario` (`email_usuario`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id_cliente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `configuracoes_turnos`
--
ALTER TABLE `configuracoes_turnos`
  MODIFY `id_config` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `cronograma`
--
ALTER TABLE `cronograma`
  MODIFY `id_aula` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2031;

--
-- AUTO_INCREMENT de tabela `cursos`
--
ALTER TABLE `cursos`
  MODIFY `id_curso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT de tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  MODIFY `id_funcionario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de tabela `historico_sistema`
--
ALTER TABLE `historico_sistema`
  MODIFY `id_historico` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT de tabela `manutencoes`
--
ALTER TABLE `manutencoes`
  MODIFY `id_manutencao` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT de tabela `progresso_curso`
--
ALTER TABLE `progresso_curso`
  MODIFY `id_progresso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de tabela `recessos`
--
ALTER TABLE `recessos`
  MODIFY `id_recesso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT de tabela `reservas`
--
ALTER TABLE `reservas`
  MODIFY `id_reserva` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `salas`
--
ALTER TABLE `salas`
  MODIFY `id_sala` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT de tabela `unidades`
--
ALTER TABLE `unidades`
  MODIFY `id_unidade` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `unidades_nova`
--
ALTER TABLE `unidades_nova`
  MODIFY `id_unidade` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `cronograma`
--
ALTER TABLE `cronograma`
  ADD CONSTRAINT `fk_cronograma_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `cursos`
--
ALTER TABLE `cursos`
  ADD CONSTRAINT `fk_curso_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `funcionarios`
--
ALTER TABLE `funcionarios`
  ADD CONSTRAINT `fk_funcionario_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `manutencoes`
--
ALTER TABLE `manutencoes`
  ADD CONSTRAINT `fk_manutencao_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `recessos`
--
ALTER TABLE `recessos`
  ADD CONSTRAINT `fk_recesso_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `reservas`
--
ALTER TABLE `reservas`
  ADD CONSTRAINT `fk_reserva_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `salas`
--
ALTER TABLE `salas`
  ADD CONSTRAINT `fk_sala_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `unidades`
--
ALTER TABLE `unidades`
  ADD CONSTRAINT `fk_unidade_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `unidades_nova`
--
ALTER TABLE `unidades_nova`
  ADD CONSTRAINT `fk_unidade_cliente_nova` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Restrições para tabelas `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD CONSTRAINT `fk_usuario_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
