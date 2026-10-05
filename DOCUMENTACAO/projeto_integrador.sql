-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 05-Out-2026 às 14:04
-- Versão do servidor: 10.4.19-MariaDB
-- versão do PHP: 8.0.7

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `projeto_integrador`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `clientes`
--

CREATE TABLE `clientes` (
  `id_cliente` int(11) NOT NULL,
  `nome_cliente` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_cliente` enum('escola','salão','empresa','outro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cnpj_cliente` varchar(18) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_cliente` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone_cliente` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `endereco_cliente` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cidade_cliente` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado_cliente` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_cliente` enum('ativo','inativo','pendente','bloqueado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `plano_cliente` enum('gratuito','basico','profissional','enterprise') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gratuito',
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `data_vencimento` date DEFAULT NULL,
  `limite_unidades` int(11) DEFAULT 1,
  `limite_usuarios` int(11) DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `configuracoes_turnos`
--

CREATE TABLE `configuracoes_turnos` (
  `id_config` int(11) NOT NULL,
  `turno` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `intervalo_inicio` time DEFAULT NULL,
  `intervalo_fim` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Extraindo dados da tabela `configuracoes_turnos`
--

INSERT INTO `configuracoes_turnos` (`id_config`, `turno`, `horario_inicio`, `horario_fim`, `intervalo_inicio`, `intervalo_fim`) VALUES
(1, 'manha', '08:00:00', '12:00:00', '10:00:00', '10:15:00'),
(2, 'tarde', '13:00:00', '17:00:00', '15:00:00', '15:15:00'),
(3, 'noite', '19:00:00', '22:30:00', '20:30:00', '20:45:00');

-- --------------------------------------------------------

--
-- Estrutura da tabela `cronograma`
--

CREATE TABLE `cronograma` (
  `id_aula` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `id_curso` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `id_professor` int(11) DEFAULT 0,
  `dias_letivos` int(11) DEFAULT NULL,
  `data_aula` date NOT NULL,
  `turno` enum('manha','tarde','noite') COLLATE utf8mb4_unicode_ci NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `status_aula` enum('agendada','realizada','cancelada','remarcada','aguardando_remarcacao') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agendada',
  `observacao` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `cursos`
--

CREATE TABLE `cursos` (
  `id_curso` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `id_docente` int(11) DEFAULT NULL,
  `numero_curso` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_curso` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `carga_horaria_curso` int(11) NOT NULL,
  `horas_por_dia` int(11) NOT NULL DEFAULT 4,
  `tipo_sala_preferencial` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_inicio_curso` date NOT NULL,
  `data_fim_curso_calculada` date DEFAULT NULL,
  `dias_letivos` int(11) DEFAULT NULL,
  `turno_curso` enum('manha','tarde','noite','integral') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dias_semana` set('segunda','terca','quarta','quinta','sexta','sabado') COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_curso` enum('curso_agil','curso_tecnico','pos_graduacao') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_curso` enum('ativo','inativo','concluido') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `percentual_conclusao` decimal(5,2) DEFAULT 0.00,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `funcionarios`
--

CREATE TABLE `funcionarios` (
  `id_funcionario` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_funcionario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cargo_funcionario` enum('administrador','coordenador','professor','auxiliar','gerente','secretaria','portaria') COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_unidade` int(11) DEFAULT NULL,
  `email_funcionario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone_funcionario` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `senha_funcionario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_acesso` enum('ativo','inativo','bloqueado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inativo',
  `data_ultimo_acesso` datetime DEFAULT NULL,
  `tentativas_login` int(11) NOT NULL DEFAULT 0,
  `data_cadastro_funcionario` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `historico_sistema`
--

CREATE TABLE `historico_sistema` (
  `id_historico` int(11) NOT NULL,
  `id_funcionario` int(11) NOT NULL,
  `tabela_afetada` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_registro_afetado` int(11) NOT NULL,
  `acao` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dados_anteriores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dados_novos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `motivo` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_acao` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_origem` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `manutencoes`
--

CREATE TABLE `manutencoes` (
  `id_manutencao` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `turno` enum('manha','tarde','noite','integral') COLLATE utf8mb4_unicode_ci NOT NULL,
  `motivo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('agendada','em_andamento','concluida') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agendada',
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `progresso_curso`
--

CREATE TABLE `progresso_curso` (
  `id_progresso` int(11) NOT NULL,
  `id_curso` int(11) NOT NULL,
  `data_registro` date NOT NULL,
  `aulas_realizadas` int(11) NOT NULL DEFAULT 0,
  `aulas_previstas` int(11) NOT NULL,
  `percentual_conclusao` decimal(5,2) GENERATED ALWAYS AS (`aulas_realizadas` / nullif(`aulas_previstas`,0) * 100) STORED,
  `observacao` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `recessos`
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `reservas`
--

CREATE TABLE `reservas` (
  `id_reserva` int(11) NOT NULL,
  `id_sala` int(11) NOT NULL,
  `id_admin` int(11) NOT NULL,
  `titulo_reserva` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_reserva` date NOT NULL,
  `turno` enum('manha','tarde','noite') COLLATE utf8mb4_unicode_ci NOT NULL,
  `horario_inicio` time NOT NULL,
  `horario_fim` time NOT NULL,
  `status_reserva` enum('ativa','cancelada','concluida') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativa',
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `salas`
--

CREATE TABLE `salas` (
  `id_sala` int(11) NOT NULL,
  `id_unidade` int(11) NOT NULL,
  `andar_sala` int(11) NOT NULL,
  `numero_sala` int(11) NOT NULL,
  `tipo_sala` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sala_aula',
  `capacidade_sala` int(11) NOT NULL DEFAULT 30,
  `recursos_sala` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_sala` enum('disponivel','ocupada','manutencao','inativa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'disponivel',
  `descricao_sala` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `unidades`
--

CREATE TABLE `unidades` (
  `id_unidade` int(11) NOT NULL,
  `nome_unidade` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado_unidade` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cidade_unidade` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endereco_unidade` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone_unidade` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_unidade` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_unidade` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `fuso` enum('America/Noronha','America/Belem','America/Fortaleza','America/Recife','America/Araguaina','America/Maceio','America/Bahia','America/Sao_Paulo','America/Campo_Grande','America/Cuiaba','America/Santarem','America/Porto_Velho','America/Boa_Vista','America/Manaus','America/Eirunepe','America/Rio_Branco') COLLATE utf8mb4_unicode_ci DEFAULT 'America/Sao_Paulo',
  `id_cliente` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `unidades_nova`
--

CREATE TABLE `unidades_nova` (
  `id_unidade` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_unidade` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado_unidade` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cidade_unidade` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endereco_unidade` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone_unidade` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_unidade` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_unidade` enum('ativo','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `fuso` enum('America/Noronha','America/Belem','America/Fortaleza','America/Recife','America/Araguaina','America/Maceio','America/Bahia','America/Sao_Paulo','America/Campo_Grande','America/Cuiaba','America/Santarem','America/Porto_Velho','America/Boa_Vista','America/Manaus','America/Eirunepe','America/Rio_Branco') COLLATE utf8mb4_unicode_ci DEFAULT 'America/Sao_Paulo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `usuarios_sistema`
--

CREATE TABLE `usuarios_sistema` (
  `id_usuario` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `nome_usuario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_usuario` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha_usuario` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_usuario` enum('admin_cliente','gerente','usuario','visualizador') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'usuario',
  `status_usuario` enum('ativo','inativo','pendente','bloqueado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `telefone_usuario` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp(),
  `data_ultimo_acesso` datetime DEFAULT NULL,
  `tentativas_login` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id_cliente`),
  ADD UNIQUE KEY `email_cliente` (`email_cliente`),
  ADD UNIQUE KEY `cnpj_cliente` (`cnpj_cliente`);

--
-- Índices para tabela `configuracoes_turnos`
--
ALTER TABLE `configuracoes_turnos`
  ADD PRIMARY KEY (`id_config`),
  ADD UNIQUE KEY `turno` (`turno`);

--
-- Índices para tabela `cronograma`
--
ALTER TABLE `cronograma`
  ADD PRIMARY KEY (`id_aula`),
  ADD UNIQUE KEY `unique_sala_horario` (`id_sala`,`data_aula`,`horario_inicio`,`horario_fim`),
  ADD KEY `id_curso` (`id_curso`),
  ADD KEY `id_professor` (`id_professor`),
  ADD KEY `fk_cronograma_unidade` (`id_unidade`),
  ADD KEY `fk_cronograma_cliente` (`id_cliente`);

--
-- Índices para tabela `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`id_curso`),
  ADD UNIQUE KEY `numero_curso` (`numero_curso`),
  ADD KEY `id_unidade` (`id_unidade`),
  ADD KEY `id_docente` (`id_docente`),
  ADD KEY `fk_curso_cliente` (`id_cliente`);

--
-- Índices para tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  ADD PRIMARY KEY (`id_funcionario`),
  ADD UNIQUE KEY `email_funcionario` (`email_funcionario`),
  ADD KEY `id_unidade` (`id_unidade`),
  ADD KEY `fk_funcionario_cliente` (`id_cliente`);

--
-- Índices para tabela `historico_sistema`
--
ALTER TABLE `historico_sistema`
  ADD PRIMARY KEY (`id_historico`),
  ADD KEY `id_funcionario` (`id_funcionario`);

--
-- Índices para tabela `manutencoes`
--
ALTER TABLE `manutencoes`
  ADD PRIMARY KEY (`id_manutencao`),
  ADD KEY `id_sala` (`id_sala`),
  ADD KEY `fk_manutencao_cliente` (`id_cliente`);

--
-- Índices para tabela `progresso_curso`
--
ALTER TABLE `progresso_curso`
  ADD PRIMARY KEY (`id_progresso`),
  ADD KEY `id_curso` (`id_curso`);

--
-- Índices para tabela `recessos`
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
-- Índices para tabela `reservas`
--
ALTER TABLE `reservas`
  ADD PRIMARY KEY (`id_reserva`),
  ADD UNIQUE KEY `unique_reserva_horario` (`id_sala`,`data_reserva`,`horario_inicio`,`horario_fim`,`status_reserva`),
  ADD KEY `id_admin` (`id_admin`),
  ADD KEY `fk_reserva_cliente` (`id_cliente`);

--
-- Índices para tabela `salas`
--
ALTER TABLE `salas`
  ADD PRIMARY KEY (`id_sala`),
  ADD UNIQUE KEY `id_unidade` (`id_unidade`,`numero_sala`),
  ADD KEY `fk_sala_cliente` (`id_cliente`);

--
-- Índices para tabela `unidades`
--
ALTER TABLE `unidades`
  ADD PRIMARY KEY (`id_unidade`),
  ADD UNIQUE KEY `nome_unidade` (`nome_unidade`),
  ADD KEY `fk_unidade_cliente` (`id_cliente`);

--
-- Índices para tabela `unidades_nova`
--
ALTER TABLE `unidades_nova`
  ADD PRIMARY KEY (`id_unidade`),
  ADD UNIQUE KEY `nome_unidade` (`nome_unidade`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Índices para tabela `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `email_usuario` (`email_usuario`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- AUTO_INCREMENT de tabelas despejadas
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
-- Restrições para despejos de tabelas
--

--
-- Limitadores para a tabela `cronograma`
--
ALTER TABLE `cronograma`
  ADD CONSTRAINT `fk_cronograma_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `cursos`
--
ALTER TABLE `cursos`
  ADD CONSTRAINT `fk_curso_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `funcionarios`
--
ALTER TABLE `funcionarios`
  ADD CONSTRAINT `fk_funcionario_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `manutencoes`
--
ALTER TABLE `manutencoes`
  ADD CONSTRAINT `fk_manutencao_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `recessos`
--
ALTER TABLE `recessos`
  ADD CONSTRAINT `fk_recesso_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `reservas`
--
ALTER TABLE `reservas`
  ADD CONSTRAINT `fk_reserva_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `salas`
--
ALTER TABLE `salas`
  ADD CONSTRAINT `fk_sala_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `unidades`
--
ALTER TABLE `unidades`
  ADD CONSTRAINT `fk_unidade_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `unidades_nova`
--
ALTER TABLE `unidades_nova`
  ADD CONSTRAINT `fk_unidade_cliente_nova` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `usuarios_sistema`
--
ALTER TABLE `usuarios_sistema`
  ADD CONSTRAINT `fk_usuario_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
