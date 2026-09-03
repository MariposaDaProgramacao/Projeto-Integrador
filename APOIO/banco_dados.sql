-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 02/09/2026 às 14:22
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
  `observacao` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `percentual_conclusao` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `funcionarios`
--

CREATE TABLE `funcionarios` (
  `id_funcionario` int(11) NOT NULL,
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

INSERT INTO `funcionarios` (`id_funcionario`, `nome_funcionario`, `cargo_funcionario`, `id_unidade`, `email_funcionario`, `telefone_funcionario`, `senha_funcionario`, `status_acesso`, `data_ultimo_acesso`, `tentativas_login`, `data_cadastro_funcionario`) VALUES
(1, 'Administrador Sistema', 'administrador', NULL, 'admin@senac.br', '(31) 99999-9999', '$2y$10$NfQyh2dap4isFZMESBoIc.VF1V.7hVRll.JOVzwRO/6wU2f4xqrAu', 'ativo', '2026-08-28 11:14:56', 0, '2026-06-30 20:44:48');

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
  `status` enum('agendada','em_andamento','concluida') NOT NULL DEFAULT 'agendada'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `data_atualizacao` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `descricao_sala` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `fuso` enum('America/Noronha','America/Belem','America/Fortaleza','America/Recife','America/Araguaina','America/Maceio','America/Bahia','America/Sao_Paulo','America/Campo_Grande','America/Cuiaba','America/Santarem','America/Porto_Velho','America/Boa_Vista','America/Manaus','America/Eirunepe','America/Rio_Branco') DEFAULT 'America/Sao_Paulo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `unidades`
--

INSERT INTO `unidades` (`id_unidade`, `nome_unidade`, `estado_unidade`, `cidade_unidade`, `endereco_unidade`, `telefone_unidade`, `email_unidade`, `status_unidade`, `fuso`) VALUES
(1, 'Unidade Para Testes do Sistema', 'MG', 'Belo Horizonte', 'Rua Goitacazes', '31999999999', 'admin@senac.br', 'ativo', 'America/Sao_Paulo'),
(2, 'Unidade Barro Preto', 'MG', 'Belo Horizonte', 'Rua dos Goitacazes 1159', '0800 724 4440', 'barropreto@senac.com', 'ativo', 'America/Sao_Paulo');

--
-- Índices para tabelas despejadas
--

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
  ADD KEY `fk_cronograma_unidade` (`id_unidade`);

--
-- Índices de tabela `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`id_curso`),
  ADD UNIQUE KEY `numero_curso` (`numero_curso`),
  ADD KEY `id_unidade` (`id_unidade`),
  ADD KEY `id_docente` (`id_docente`);

--
-- Índices de tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  ADD PRIMARY KEY (`id_funcionario`),
  ADD UNIQUE KEY `email_funcionario` (`email_funcionario`),
  ADD KEY `id_unidade` (`id_unidade`);

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
  ADD KEY `id_sala` (`id_sala`);

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
  ADD KEY `idx_tipo` (`tipo_curso`);

--
-- Índices de tabela `reservas`
--
ALTER TABLE `reservas`
  ADD PRIMARY KEY (`id_reserva`),
  ADD UNIQUE KEY `unique_reserva_horario` (`id_sala`,`data_reserva`,`horario_inicio`,`horario_fim`,`status_reserva`),
  ADD KEY `id_admin` (`id_admin`);

--
-- Índices de tabela `salas`
--
ALTER TABLE `salas`
  ADD PRIMARY KEY (`id_sala`),
  ADD UNIQUE KEY `id_unidade` (`id_unidade`,`numero_sala`);

--
-- Índices de tabela `unidades`
--
ALTER TABLE `unidades`
  ADD PRIMARY KEY (`id_unidade`),
  ADD UNIQUE KEY `nome_unidade` (`nome_unidade`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `configuracoes_turnos`
--
ALTER TABLE `configuracoes_turnos`
  MODIFY `id_config` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `cronograma`
--
ALTER TABLE `cronograma`
  MODIFY `id_aula` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2006;

--
-- AUTO_INCREMENT de tabela `cursos`
--
ALTER TABLE `cursos`
  MODIFY `id_curso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT de tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  MODIFY `id_funcionario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de tabela `historico_sistema`
--
ALTER TABLE `historico_sistema`
  MODIFY `id_historico` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de tabela `manutencoes`
--
ALTER TABLE `manutencoes`
  MODIFY `id_manutencao` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de tabela `progresso_curso`
--
ALTER TABLE `progresso_curso`
  MODIFY `id_progresso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `recessos`
--
ALTER TABLE `recessos`
  MODIFY `id_recesso` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT de tabela `reservas`
--
ALTER TABLE `reservas`
  MODIFY `id_reserva` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `salas`
--
ALTER TABLE `salas`
  MODIFY `id_sala` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT de tabela `unidades`
--
ALTER TABLE `unidades`
  MODIFY `id_unidade` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;