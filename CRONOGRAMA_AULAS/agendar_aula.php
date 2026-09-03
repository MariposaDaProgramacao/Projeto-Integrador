<?php
// ==========================================================
// agendar_aula.php - Cadastro de Aulas (Múltiplas) com Sugestão de Salas
// MODIFICADO PARA MULTI-TENANT
// ==========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// PERMISSÕES - APENAS ADMINISTRADOR E COORDENADOR (NOVO SISTEMA)
// ============================================================
$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem cadastrar aulas.');
    redirect('listar_aulas.php');
}

$caminhoBanco = __DIR__ . '/../conexao_banco.php';
if (!file_exists($caminhoBanco)) {
    die('Arquivo de conexão não encontrado.');
}
require_once $caminhoBanco;
if (!isset($conn)) {
    die('Erro: conexão com banco não estabelecida.');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$id_unidade_usuario = $_SESSION['usuario_unidade'] ?? null;

// Se não tiver unidade definida, buscar a primeira unidade do cliente
if ($id_unidade_usuario == 0 || $id_unidade_usuario === null) {
    try {
        $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
        $stmt->execute([$id_cliente]);
        $unidade = $stmt->fetch();
        if ($unidade) {
            $id_unidade_usuario = $unidade['id_unidade'];
            $_SESSION['usuario_unidade'] = $id_unidade_usuario;
        }
    } catch (PDOException $e) {
        $id_unidade_usuario = 0;
    }
}

// ============================================================
// FUNÇÃO PARA BUSCAR DIAS DE RECESSO DA UNIDADE (COM FILTRO POR CURSO)
// ============================================================
function buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso = null) {
    try {
        $sql = "SELECT data_inicio, data_fim, id_cursos 
                FROM recessos 
                WHERE id_unidade = :id_unidade 
                AND id_cliente = :id_cliente
                AND ativo = 1";
        
        $params = [
            ':id_unidade' => $id_unidade,
            ':id_cliente' => $id_cliente
        ];
        
        if ($id_curso) {
            $sql .= " AND (id_cursos IS NULL OR FIND_IN_SET(:id_curso, id_cursos) > 0)";
            $params[':id_curso'] = $id_curso;
        }
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $recessos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $diasRecesso = [];
        foreach ($recessos as $recesso) {
            $dataInicio = new DateTime($recesso['data_inicio']);
            $dataFim = new DateTime($recesso['data_fim']);
            $dataFim->modify('+1 day');
            
            while ($dataInicio < $dataFim) {
                $diasRecesso[] = $dataInicio->format('Y-m-d');
                $dataInicio->modify('+1 day');
            }
        }
        
        return $diasRecesso;
    } catch (PDOException $e) {
        return [];
    }
}

// ============================================================
// BUSCAR LISTAS PARA SELECTS (FILTRADAS POR CLIENTE)
// ============================================================
try {
    $sqlProfessores = "SELECT id_funcionario, nome_funcionario 
                       FROM funcionarios 
                       WHERE cargo_funcionario = 'professor' 
                       AND status_acesso = 'ativo'
                       AND id_cliente = :id_cliente 
                       ORDER BY nome_funcionario";
    $stmtProfessores = $conn->prepare($sqlProfessores);
    $stmtProfessores->execute([':id_cliente' => $id_cliente]);
    $professores = $stmtProfessores->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $professores = [];
}

// ============================================================
// BUSCAR CURSOS DISPONÍVEIS PARA CADASTRO (FILTRADOS POR CLIENTE)
// ============================================================
try {
    $sqlCursos = "SELECT 
                    c.id_curso,
                    c.numero_curso,
                    c.nome_curso,
                    c.id_unidade,
                    c.dias_letivos,
                    c.data_inicio_curso,
                    c.data_fim_curso_calculada,
                    c.dias_semana,
                    c.turno_curso,
                    c.status_curso,
                    c.id_cliente,
                    SUM(CASE WHEN cron.status_aula != 'cancelada' THEN 1 ELSE 0 END) AS aulas_ativas
                FROM cursos c
                LEFT JOIN cronograma cron ON c.id_curso = cron.id_curso AND cron.id_cliente = c.id_cliente
                WHERE c.status_curso = 'ativo'
                AND c.id_cliente = :id_cliente";
    
    if ($tipo_usuario === 'gerente') {
        $sqlCursos .= " AND c.id_unidade = :id_unidade";
    }
    
    $sqlCursos .= " GROUP BY c.id_curso";
    $sqlCursos .= " HAVING SUM(CASE WHEN cron.status_aula != 'cancelada' THEN 1 ELSE 0 END) < c.dias_letivos 
                     OR c.dias_letivos IS NULL 
                     OR SUM(CASE WHEN cron.status_aula != 'cancelada' THEN 1 ELSE 0 END) = 0";
    $sqlCursos .= " ORDER BY c.data_inicio_curso ASC, c.numero_curso, c.nome_curso";
    
    $stmtCursos = $conn->prepare($sqlCursos);
    $params = [':id_cliente' => $id_cliente];
    if ($tipo_usuario === 'gerente') {
        $params[':id_unidade'] = $id_unidade_usuario;
    }
    $stmtCursos->execute($params);
    
    $cursos = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $cursos = [];
    $mensagem_erro = '❌ Erro ao buscar cursos: ' . $e->getMessage();
}

// ============================================================
// BUSCAR TODAS AS SALAS DA UNIDADE (FILTRADAS POR CLIENTE)
// ============================================================
try {
    if ($tipo_usuario === 'admin_cliente') {
        $sqlSalas = "SELECT 
                        id_sala, 
                        numero_sala, 
                        id_unidade,
                        tipo_sala,
                        capacidade_sala,
                        recursos_sala,
                        status_sala,
                        descricao_sala
                    FROM salas 
                    WHERE id_cliente = :id_cliente
                    AND status_sala != 'inativa'
                    ORDER BY numero_sala";
        $stmtSalas = $conn->prepare($sqlSalas);
        $stmtSalas->execute([':id_cliente' => $id_cliente]);
    } else {
        $sqlSalas = "SELECT 
                        id_sala, 
                        numero_sala, 
                        id_unidade,
                        tipo_sala,
                        capacidade_sala,
                        recursos_sala,
                        status_sala,
                        descricao_sala
                    FROM salas 
                    WHERE id_unidade = :id_unidade 
                    AND id_cliente = :id_cliente
                    AND status_sala != 'inativa'
                    ORDER BY numero_sala";
        $stmtSalas = $conn->prepare($sqlSalas);
        $stmtSalas->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
    }
    $todasSalas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todasSalas = [];
}

// ============================================================
// PROCESSAR FORMULÁRIO DE CADASTRO
// ============================================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_curso = $_POST['id_curso'] ?? '';
    $id_professor = $_POST['id_professor'] ?? null;
    $id_sala = $_POST['id_sala'] ?? '';
    $horario_inicio = $_POST['horario_inicio'] ?? '';
    $horario_fim = $_POST['horario_fim'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $status_aula = $_POST['status_aula'] ?? 'agendada';
    $observacao = $_POST['observacao'] ?? '';
    
    // ============================================================
    // DETERMINAR O ID_UNIDADE
    // ============================================================
    try {
        $sqlBuscarUnidade = "SELECT id_unidade FROM cursos WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtBuscarUnidade = $conn->prepare($sqlBuscarUnidade);
        $stmtBuscarUnidade->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $cursoUnidade = $stmtBuscarUnidade->fetch(PDO::FETCH_ASSOC);
        $id_unidade = $cursoUnidade['id_unidade'] ?? null;
        
        if ($tipo_usuario === 'gerente') {
            $id_unidade = $id_unidade_usuario;
        }
    } catch (PDOException $e) {
        $id_unidade = null;
    }
    
    // Validação básica
    $erros = [];
    if (empty($id_curso)) $erros[] = 'Curso é obrigatório.';
    if (empty($id_sala)) $erros[] = 'Sala é obrigatória.';
    if (empty($horario_inicio)) $erros[] = 'Horário de início é obrigatório.';
    if (empty($horario_fim)) $erros[] = 'Horário de fim é obrigatório.';
    if (empty($turno)) $erros[] = 'Turno é obrigatório.';
    if (empty($id_unidade)) $erros[] = 'Unidade não identificada.';
    
    if (!empty($horario_inicio) && !empty($horario_fim) && $horario_inicio >= $horario_fim) {
        $erros[] = 'Horário de início deve ser anterior ao horário de fim.';
    }
    
    if (empty($erros)) {
        try {
            $sqlCurso = "SELECT 
                            data_inicio_curso, 
                            data_fim_curso_calculada, 
                            dias_letivos,
                            dias_semana,
                            numero_curso,
                            nome_curso
                        FROM cursos 
                        WHERE id_curso = :id_curso 
                        AND id_cliente = :id_cliente
                        AND status_curso = 'ativo'";
            $stmtCurso = $conn->prepare($sqlCurso);
            $stmtCurso->execute([
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            $curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
            
            if (!$curso) {
                $mensagem_erro = '❌ Curso não encontrado ou não está ativo.';
            } else {
                $dataInicio = $curso['data_inicio_curso'];
                $diasLetivos = (int)$curso['dias_letivos'];
                $diasSemanaCurso = $curso['dias_semana'];
                $id_curso_atual = $id_curso;
                
                if (empty($dataInicio)) {
                    $mensagem_erro = '❌ O curso não possui data de início definida.';
                } else {
                    $sqlVerificar = "SELECT COUNT(*) as total FROM cronograma WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                    $stmtVerificar = $conn->prepare($sqlVerificar);
                    $stmtVerificar->execute([
                        ':id_curso' => $id_curso,
                        ':id_cliente' => $id_cliente
                    ]);
                    $aulasExistentes = $stmtVerificar->fetchColumn();
                    
                    if ($aulasExistentes >= $diasLetivos && $diasLetivos > 0) {
                        $mensagem_erro = '⚠️ Este curso já possui todas as aulas cadastradas (' . $diasLetivos . ' aulas).';
                    } else {
                        // ============================================================
                        // BUSCAR DIAS DE RECESSO - FILTRANDO PELO CURSO E CLIENTE
                        // ============================================================
                        $diasRecesso = buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso_atual);
                        
                        // Preparar dias da semana permitidos
                        $diasSemanaArray = explode(',', $diasSemanaCurso);
                        $diasSemanaMap = [
                            'segunda' => 1, 'terca' => 2, 'quarta' => 3,
                            'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
                        ];
                        
                        $diasPermitidos = [];
                        foreach ($diasSemanaArray as $dia) {
                            $dia = trim($dia);
                            if (isset($diasSemanaMap[$dia])) {
                                $diasPermitidos[] = $diasSemanaMap[$dia];
                            }
                        }
                        
                        // ============================================================
                        // GERAR DATAS - COM EMPURRAMENTO DE RECESSOS
                        // ============================================================
                        $dataAtual = new DateTime($dataInicio);
                        $contadorDias = 0;
                        $datasGeradas = [];
                        $diasPuladosPorRecesso = [];
                        $totalDiasPercorridos = 0;
                        $limiteSeguranca = 365;
                        
                        while ($contadorDias < $diasLetivos && $totalDiasPercorridos < $limiteSeguranca) {
                            $diaSemana = (int)$dataAtual->format('N');
                            $dataFormatada = $dataAtual->format('Y-m-d');
                            
                            if (in_array($dataFormatada, $diasRecesso)) {
                                $diasPuladosPorRecesso[] = $dataFormatada;
                                $dataAtual->modify('+1 day');
                                $totalDiasPercorridos++;
                                continue;
                            }
                            
                            if (in_array($diaSemana, $diasPermitidos)) {
                                $datasGeradas[] = clone $dataAtual;
                                $contadorDias++;
                            }
                            
                            $dataAtual->modify('+1 day');
                            $totalDiasPercorridos++;
                        }
                        
                        if (empty($datasGeradas)) {
                            $mensagem_erro = '❌ Nenhuma data letiva encontrada para o curso.';
                        } else {
                            // ============================================================
                            // VERIFICAR CONFLITOS
                            // ============================================================
                            $conflitos = [];
                            $conflitosDetalhados = [];
                            
                            foreach ($datasGeradas as $dataAula) {
                                $dataFormatada = $dataAula->format('Y-m-d');
                                
                                $sqlCheckDetalhado = "SELECT 
                                                        cron.id_aula,
                                                        cron.horario_inicio,
                                                        cron.horario_fim,
                                                        cursos.nome_curso,
                                                        funcionarios.nome_funcionario AS professor
                                                    FROM cronograma cron
                                                    LEFT JOIN cursos ON cron.id_curso = cursos.id_curso AND cursos.id_cliente = cron.id_cliente
                                                    LEFT JOIN funcionarios ON cron.id_professor = funcionarios.id_funcionario AND funcionarios.id_cliente = cron.id_cliente
                                                    WHERE cron.id_sala = :id_sala 
                                                    AND cron.data_aula = :data_aula 
                                                    AND cron.id_cliente = :id_cliente
                                                    AND (
                                                        (cron.horario_inicio < :horario_fim AND cron.horario_fim > :horario_inicio)
                                                    )";
                                $stmtCheckDetalhado = $conn->prepare($sqlCheckDetalhado);
                                $stmtCheckDetalhado->execute([
                                    ':id_sala' => $id_sala,
                                    ':data_aula' => $dataFormatada,
                                    ':horario_inicio' => $horario_inicio,
                                    ':horario_fim' => $horario_fim,
                                    ':id_cliente' => $id_cliente
                                ]);
                                $aulaConflitante = $stmtCheckDetalhado->fetch(PDO::FETCH_ASSOC);
                                
                                if ($aulaConflitante) {
                                    $conflitos[] = date('d/m/Y', strtotime($dataFormatada));
                                    $conflitosDetalhados[] = [
                                        'data' => date('d/m/Y', strtotime($dataFormatada)),
                                        'curso' => $aulaConflitante['nome_curso'] ?? 'Não definido',
                                        'horario' => substr($aulaConflitante['horario_inicio'] ?? '', 0, 5) . ' - ' . substr($aulaConflitante['horario_fim'] ?? '', 0, 5),
                                        'professor' => $aulaConflitante['professor'] ?? 'Não definido'
                                    ];
                                }
                            }
                            
                            if (!empty($conflitos)) {
                                $mensagemErro = '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">';
                                $mensagemErro .= '<strong><i class="fas fa-exclamation-triangle" style="color: #856404;"></i> Conflito de horário detectado!</strong><br><br>';
                                $mensagemErro .= 'A sala selecionada <strong>não está disponível</strong> em todas as datas do curso.<br>';
                                $mensagemErro .= 'Foram encontrados conflitos em <strong>' . count($conflitos) . ' data(s)</strong>:<br><br>';
                                $mensagemErro .= '<ul style="margin: 10px 0; padding-left: 20px;">';
                                
                                foreach ($conflitosDetalhados as $conflito) {
                                    $mensagemErro .= '<li>📅 <strong>' . $conflito['data'] . '</strong> - ';
                                    $mensagemErro .= 'Ocupado por: <strong>' . htmlspecialchars($conflito['curso']) . '</strong> ';
                                    $mensagemErro .= '(' . $conflito['horario'] . ') ';
                                    $mensagemErro .= '| Professor: ' . htmlspecialchars($conflito['professor']);
                                    $mensagemErro .= '</li>';
                                }
                                
                                $mensagemErro .= '</ul><br>';
                                $mensagemErro .= '💡 <strong>Sugestões:</strong><br>';
                                $mensagemErro .= '• Escolha outra sala disponível (veja a lista de salas sugeridas abaixo)<br>';
                                $mensagemErro .= '• Altere o horário das aulas<br>';
                                $mensagemErro .= '• Verifique os dias da semana do curso';
                                $mensagemErro .= '</div>';
                                
                                $mensagem_erro = $mensagemErro;
                            } else {
                                // ============================================================
                                // INSERIR AULAS
                                // ============================================================
                                $conn->beginTransaction();
                                
                                try {
                                    $sqlInsert = "INSERT INTO cronograma (
                                                    id_curso,
                                                    id_professor,
                                                    id_sala,
                                                    id_unidade,
                                                    id_cliente,
                                                    data_aula,
                                                    horario_inicio,
                                                    horario_fim,
                                                    turno,
                                                    status_aula,
                                                    observacao
                                                ) VALUES (
                                                    :id_curso,
                                                    :id_professor,
                                                    :id_sala,
                                                    :id_unidade,
                                                    :id_cliente,
                                                    :data_aula,
                                                    :horario_inicio,
                                                    :horario_fim,
                                                    :turno,
                                                    :status_aula,
                                                    :observacao
                                                )";
                                    
                                    $stmtInsert = $conn->prepare($sqlInsert);
                                    $aulasInseridas = 0;
                                    $ultimaData = null;
                                    
                                    foreach ($datasGeradas as $dataAula) {
                                        $dataFormatada = $dataAula->format('Y-m-d');
                                        
                                        $resultado = $stmtInsert->execute([
                                            ':id_curso' => $id_curso,
                                            ':id_professor' => !empty($id_professor) ? $id_professor : null,
                                            ':id_sala' => $id_sala,
                                            ':id_unidade' => $id_unidade,
                                            ':id_cliente' => $id_cliente,
                                            ':data_aula' => $dataFormatada,
                                            ':horario_inicio' => $horario_inicio,
                                            ':horario_fim' => $horario_fim,
                                            ':turno' => $turno,
                                            ':status_aula' => $status_aula,
                                            ':observacao' => $observacao
                                        ]);
                                        
                                        if ($resultado) {
                                            $aulasInseridas++;
                                            $ultimaData = $dataFormatada;
                                        }
                                    }
                                    
                                    $conn->commit();
                                    
                                    // ============================================================
                                    // ATUALIZAR DATA DE FIM DO CURSO
                                    // ============================================================
                                    if ($ultimaData) {
                                        $sqlUpdateFim = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                                                         WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                                        $stmtUpdateFim = $conn->prepare($sqlUpdateFim);
                                        $stmtUpdateFim->execute([
                                            ':data_fim' => $ultimaData,
                                            ':id_curso' => $id_curso,
                                            ':id_cliente' => $id_cliente
                                        ]);
                                    }
                                    
                                    // ============================================================
                                    // MONTAR MENSAGEM DE SUCESSO
                                    // ============================================================
                                    $mensagem = "✅ <strong>{$aulasInseridas} aulas</strong> cadastradas com sucesso para o curso " . 
                                                "<strong>" . $curso['numero_curso'] . "</strong> - " . $curso['nome_curso'] .
                                                " (<strong>" . count($datasGeradas) . "</strong> dias letivos)";

                                    if (!empty($diasPuladosPorRecesso)) {
                                        $totalRecessos = count($diasPuladosPorRecesso);
                                        $mensagem .= "<br>📅 <strong>{$totalRecessos} " . ($totalRecessos > 1 ? 'dias' : 'dia') . " de recesso</strong> foram automaticamente <strong>ignorados</strong> durante o agendamento.";
                                        $mensagem .= "<br>🔄 As aulas que cairiam nesses dias foram <strong>remanejadas</strong> para os próximos dias úteis disponíveis.";
                                    }

                                    if ($ultimaData) {
                                        $mensagem .= "<br>📅 <strong>Nova data de fim do curso:</strong> " . date('d/m/Y', strtotime($ultimaData));
                                    }

                                    if (!empty($curso['data_fim_curso_calculada']) && $ultimaData && $curso['data_fim_curso_calculada'] != $ultimaData) {
                                        $dataFimOriginal = date('d/m/Y', strtotime($curso['data_fim_curso_calculada']));
                                        $diasExtras = (strtotime($ultimaData) - strtotime($curso['data_fim_curso_calculada'])) / 86400;
                                        $mensagem .= "<br><span style='font-size: 12px; color: #7a8aa0;'>📅 Data de fim original: <strong>{$dataFimOriginal}</strong> → <strong>" . date('d/m/Y', strtotime($ultimaData)) . "</strong> (prolongado em {$diasExtras} dias)</span>";
                                    }
                                    
                                    $_SESSION['mensagem_sucesso'] = $mensagem;
                                    header('Location: listar_aulas.php');
                                    exit;
                                    
                                } catch (PDOException $e) {
                                    $conn->rollBack();
                                    $mensagem_erro = '❌ Erro ao cadastrar aulas: ' . $e->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $mensagem_erro = '❌ Erro ao processar cadastro: ' . $e->getMessage();
        }
    } else {
        $mensagem_erro = '⚠️ ' . implode(' ', $erros);
    }
}

// ============================================================
// FUNÇÃO PARA VERIFICAR SALAS DISPONÍVEIS (AJAX) - COM FILTRO DE RECESSO
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'verificar_salas') {
    header('Content-Type: application/json');
    
    $id_curso = $_GET['id_curso'] ?? 0;
    $horario_inicio = $_GET['horario_inicio'] ?? '';
    $horario_fim = $_GET['horario_fim'] ?? '';
    $turno = $_GET['turno'] ?? '';
    
    if (empty($id_curso) || empty($horario_inicio) || empty($horario_fim)) {
        echo json_encode(['error' => 'Dados incompletos']);
        exit;
    }
    
    try {
        // Buscar dados do curso
        $sqlCurso = "SELECT data_inicio_curso, data_fim_curso_calculada, dias_letivos, dias_semana, id_unidade 
                     FROM cursos 
                     WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtCurso = $conn->prepare($sqlCurso);
        $stmtCurso->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
        
        if (!$curso) {
            echo json_encode(['error' => 'Curso não encontrado']);
            exit;
        }
        
        // ============================================================
        // BUSCAR DIAS DE RECESSO - FILTRANDO PELO CURSO
        // ============================================================
        $diasRecesso = buscarDiasRecesso($conn, $curso['id_unidade'], $id_cliente, $id_curso);
        
        // Preparar dias da semana
        $diasSemanaArray = explode(',', $curso['dias_semana']);
        $diasSemanaMap = [
            'segunda' => 1, 'terca' => 2, 'quarta' => 3,
            'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
        ];
        
        $diasPermitidos = [];
        foreach ($diasSemanaArray as $dia) {
            $dia = trim($dia);
            if (isset($diasSemanaMap[$dia])) {
                $diasPermitidos[] = $diasSemanaMap[$dia];
            }
        }
        
        // Gerar datas com empurramento
        $dataAtual = new DateTime($curso['data_inicio_curso']);
        $diasLetivos = (int)$curso['dias_letivos'];
        $contadorDias = 0;
        $datasGeradas = [];
        $totalDiasPercorridos = 0;
        $limiteSeguranca = 365;
        
        while ($contadorDias < $diasLetivos && $totalDiasPercorridos < $limiteSeguranca) {
            $diaSemana = (int)$dataAtual->format('N');
            $dataFormatada = $dataAtual->format('Y-m-d');
            
            if (in_array($dataFormatada, $diasRecesso)) {
                $dataAtual->modify('+1 day');
                $totalDiasPercorridos++;
                continue;
            }
            
            if (in_array($diaSemana, $diasPermitidos)) {
                $datasGeradas[] = clone $dataAtual;
                $contadorDias++;
            }
            
            $dataAtual->modify('+1 day');
            $totalDiasPercorridos++;
        }
        
        if (empty($datasGeradas)) {
            echo json_encode(['error' => 'Nenhuma data letiva encontrada']);
            exit;
        }
        
        // Buscar salas
        $sqlSalas = "SELECT 
                        id_sala, 
                        numero_sala, 
                        tipo_sala,
                        capacidade_sala,
                        recursos_sala,
                        status_sala,
                        descricao_sala
                    FROM salas 
                    WHERE id_cliente = :id_cliente
                    AND status_sala != 'inativa'";
        if ($tipo_usuario === 'gerente') {
            $sqlSalas .= " AND id_unidade = :id_unidade";
        }
        $sqlSalas .= " ORDER BY numero_sala";
        
        $stmtSalas = $conn->prepare($sqlSalas);
        $params = [':id_cliente' => $id_cliente];
        if ($tipo_usuario === 'gerente') {
            $params[':id_unidade'] = $id_unidade_usuario;
        }
        $stmtSalas->execute($params);
        $todasSalas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
        
        $salasComStatus = [];
        $salasDisponiveis = [];
        $totalSalas = count($todasSalas);
        $totalDisponiveis = 0;
        
        foreach ($todasSalas as $sala) {
            $disponivel = true;
            $conflitos = [];
            
            // ============================================================
            // VERIFICAR SE A SALA ESTÁ EM MANUTENÇÃO
            // ============================================================
            $emManutencao = ($sala['status_sala'] === 'manutencao');
            
            if ($emManutencao) {
                $disponivel = false;
                $conflitos[] = [
                    'data' => 'Período de manutenção',
                    'curso' => '🔧 Sala em Manutenção',
                    'horario' => 'Indisponível'
                ];
            } else {
                foreach ($datasGeradas as $dataAula) {
                    $dataFormatada = $dataAula->format('Y-m-d');
                    
                    $sqlCheck = "SELECT 
                                    cron.id_aula,
                                    cron.horario_inicio,
                                    cron.horario_fim,
                                    cursos.nome_curso
                                FROM cronograma cron
                                LEFT JOIN cursos ON cron.id_curso = cursos.id_curso AND cursos.id_cliente = cron.id_cliente
                                WHERE cron.id_sala = :id_sala 
                                AND cron.data_aula = :data_aula 
                                AND cron.id_cliente = :id_cliente
                                AND (
                                    (cron.horario_inicio < :horario_fim AND cron.horario_fim > :horario_inicio)
                                )";
                    $stmtCheck = $conn->prepare($sqlCheck);
                    $stmtCheck->execute([
                        ':id_sala' => $sala['id_sala'],
                        ':data_aula' => $dataFormatada,
                        ':horario_inicio' => $horario_inicio,
                        ':horario_fim' => $horario_fim,
                        ':id_cliente' => $id_cliente
                    ]);
                    $conflito = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    
                    if ($conflito) {
                        $disponivel = false;
                        $conflitos[] = [
                            'data' => date('d/m/Y', strtotime($dataFormatada)),
                            'curso' => $conflito['nome_curso'] ?? 'Não definido',
                            'horario' => substr($conflito['horario_inicio'] ?? '', 0, 5) . ' - ' . substr($conflito['horario_fim'] ?? '', 0, 5)
                        ];
                    }
                }
            }
            
            if ($disponivel) {
                $totalDisponiveis++;
                $salasDisponiveis[] = $sala;
            }
            
            $recursos = null;
            if (!empty($sala['recursos_sala'])) {
                $recursos = json_decode($sala['recursos_sala'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $recursos = null;
                }
            }
            
            $salasComStatus[] = [
                'sala' => [
                    'id_sala' => $sala['id_sala'],
                    'numero_sala' => $sala['numero_sala'],
                    'tipo_sala' => $sala['tipo_sala'] ?? 'sala_aula',
                    'capacidade_sala' => $sala['capacidade_sala'] ?? 30,
                    'recursos_sala' => $recursos,
                    'status_sala' => $sala['status_sala'],
                    'descricao_sala' => $sala['descricao_sala'] ?? ''
                ],
                'disponivel' => $disponivel,
                'em_manutencao' => $emManutencao,
                'conflitos' => $conflitos
            ];
        }
        
        echo json_encode([
            'success' => true,
            'salas' => $salasComStatus,
            'salas_disponiveis' => $salasDisponiveis,
            'total_salas' => $totalSalas,
            'total_disponiveis' => $totalDisponiveis,
            'total_datas' => count($datasGeradas),
            'dias_recesso' => count($diasRecesso)
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao verificar salas: ' . $e->getMessage()]);
    }
    exit;
}

$titulo = 'Cadastrar Aulas - Gerenciamento de Ambientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 28px 32px;
            margin-bottom: 20px;
            max-width: 900px;
            width: 100%;
            align-self: center;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
        }
        .page-title i {
            color: #1a73e8;
            margin-right: 10px;
        }
        .page-subtitle {
            font-size: 14px;
            color: #7a8aa0;
            margin-top: 4px;
        }

        .btn {
            padding: 9px 20px;
            border-radius: 60px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-secondary {
            background: #e2e9f3;
            color: #1a2639;
            border: 1px solid #d8e0ec;
        }
        .btn-secondary:hover {
            background: #d0dbe8;
        }

        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .btn-primary:hover {
            background: #1557b0;
            transform: scale(1.02);
        }

        .btn-success {
            background: #28a745;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(40, 167, 69, 0.35);
        }
        .btn-success:hover {
            background: #218838;
            transform: scale(1.02);
        }

        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #5a6a7e;
            margin-bottom: 4px;
        }
        .form-group label i {
            margin-right: 6px;
            color: #1a73e8;
        }
        .form-group label .required {
            color: #dc3545;
            margin-left: 2px;
        }
        .form-group label .optional {
            color: #7a8aa0;
            font-weight: 400;
            font-size: 12px;
            margin-left: 4px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            background: #fafcff;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
            outline: none;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            height: 42px;
            padding: 4px 8px;
            background: #fafcff;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1a2639;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-container--default .select2-selection--single:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }
        .select2-dropdown {
            border: 1px solid #e2e9f3;
            border-radius: 8px;
        }
        .select2-search__field {
            border-radius: 6px !important;
            border: 1px solid #e2e9f3 !important;
            padding: 6px 10px !important;
        }
        .select2-results__option {
            padding: 8px 12px !important;
        }
        .select2-results__option--highlighted {
            background: #e3f2fd !important;
            color: #1a2639 !important;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border: 1px solid #ffd6d6;
        }
        .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border: 1px solid #c8f0cf;
        }
        .alert i {
            font-size: 18px;
        }

        .info-box {
            background: #f0f7ff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid #1a73e8;
        }
        .info-box p {
            font-size: 13px;
            color: #5a6a7e;
            margin: 4px 0;
        }
        .info-box strong {
            color: #0e1a2b;
        }
        
        .info-box .highlight {
            color: #1a73e8;
            font-weight: 600;
        }
        
        .info-box.warning {
            border-left-color: #ffc107;
            background: #fff8e1;
        }

        .curso-info {
            background: #f8faff;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 15px;
            border: 1px solid #e2e9f3;
            display: none;
        }
        .curso-info.active {
            display: block;
        }
        .curso-info p {
            margin: 4px 0;
            font-size: 13px;
            color: #5a6a7e;
        }
        .curso-info strong {
            color: #0e1a2b;
        }

        .empty-cursos {
            text-align: center;
            padding: 20px;
            color: #7a8aa0;
        }
        .empty-cursos i {
            font-size: 48px;
            color: #dce3ef;
            display: block;
            margin-bottom: 12px;
        }

        .salas-sugeridas {
            margin-top: 15px;
            padding: 15px;
            background: #f8faff;
            border-radius: 8px;
            border: 1px solid #e2e9f3;
            display: none;
        }
        .salas-sugeridas.active {
            display: block;
        }
        
        .sala-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            margin-bottom: 6px;
            border-radius: 8px;
            border: 1px solid #e2e9f3;
            transition: all 0.2s;
            cursor: pointer;
        }
        .sala-item:hover {
            border-color: #1a73e8;
            box-shadow: 0 2px 8px rgba(26, 115, 232, 0.1);
        }
        .sala-item.disponivel {
            border-left: 4px solid #28a745;
        }
        .sala-item.indisponivel {
            border-left: 4px solid #dc3545;
            opacity: 0.7;
            cursor: not-allowed;
        }
        .sala-item.em-manutencao {
            border-left: 4px solid #ffc107;
            background: #fff8e1 !important;
            cursor: not-allowed;
            opacity: 0.85;
        }
        .sala-item.em-manutencao .numero {
            color: #e65100;
        }
        .sala-item.em-manutencao .detalhes {
            color: #856404;
        }
        .sala-item.em-manutencao .status-badge {
            background: #ffc107;
            color: #856404;
        }
        .sala-item.selecionada {
            background: #e3f2fd;
            border-color: #1a73e8;
            border-left: 4px solid #1a73e8;
        }
        .sala-item .info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
        }
        .sala-item .info .numero {
            font-weight: 600;
            font-size: 14px;
            color: #0e1a2b;
        }
        .sala-item .info .detalhes {
            font-size: 12px;
            color: #7a8aa0;
        }
        .sala-item .info .detalhes i {
            margin-right: 4px;
        }
        .sala-item .status-badge {
            font-size: 12px;
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .sala-item .status-badge.disponivel {
            background: #e6f7e9;
            color: #1e8546;
        }
        .sala-item .status-badge.indisponivel {
            background: #ffe9e9;
            color: #b33a3a;
        }
        .sala-item .status-badge.selecionada {
            background: #1a73e8;
            color: #ffffff;
        }
        .sala-item .status-badge.manutencao {
            background: #ffc107;
            color: #856404;
        }

        .salas-container {
            margin-top: 10px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            padding: 10px;
            background: #fafcff;
        }

        .salas-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .salas-header .total-info {
            font-size: 13px;
            color: #5a6a7e;
        }
        .salas-header .total-info strong {
            color: #0e1a2b;
        }
        .salas-header .total-info .disponivel-count {
            color: #28a745;
        }
        .salas-header .total-info .indisponivel-count {
            color: #dc3545;
        }
        .salas-header .total-info .manutencao-count {
            color: #e65100;
        }

        .loading-salas {
            display: none;
            text-align: center;
            padding: 20px;
            color: #7a8aa0;
        }
        .loading-salas i {
            font-size: 24px;
            color: #1a73e8;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .mensagem-horario {
            padding: 12px 16px;
            background: #f0f7ff;
            border-radius: 8px;
            border-left: 4px solid #1a73e8;
            color: #5a6a7e;
            display: block;
        }
        .mensagem-horario i {
            color: #1a73e8;
            margin-right: 8px;
        }
        .mensagem-horario strong {
            color: #0e1a2b;
        }

        .sala-selecionada {
            display: none;
            padding: 10px 14px;
            background: #e6f7e9;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            margin-top: 8px;
        }
        .sala-selecionada i {
            color: #28a745;
            margin-right: 8px;
        }
        .sala-selecionada strong {
            color: #1e8546;
        }
        .sala-selecionada span {
            color: #1e8546;
            font-weight: 500;
        }

        @media (max-width: 640px) {
            .main { padding: 16px; }
            .card-panel { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .sala-item { flex-direction: column; align-items: flex-start; gap: 6px; }
        }

        .sidebar {
            width: 270px;
            background: #ffffff;
            border-right: 1px solid #e8edf5;
            padding: 28px 20px;
            display: flex;
            flex-direction: column;
            gap: 28px;
            flex-shrink: 0;
            overflow-y: auto;
            height: 100vh;
        }
        .logo-area { display: flex; align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f4fb; }
        .logo-icon { background: linear-gradient(145deg, #1a73e8, #0d47a1); width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px; box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3); }
        .logo-text { font-size: 20px; font-weight: 700; color: #1a2639; }
        .logo-text span { color: #1a73e8; }
        .logo-text small { display: block; font-size: 11px; font-weight: 400; color: #7a8aa0; margin-top: 2px; }
        .sidebar-menu { display: flex; flex-direction: column; gap: 4px; flex: 1; }
        .menu-label { font-size: 11px; font-weight: 600; color: #9aabbf; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 16px 6px; }
        .menu-item { display: flex; align-items: center; gap: 14px; padding: 10px 16px; border-radius: 10px; color: #5a6a7e; font-weight: 500; font-size: 14px; transition: all 0.15s ease; text-decoration: none; }
        .menu-item i { width: 20px; font-size: 16px; color: #8a9bb5; transition: color 0.15s; }
        .menu-item:hover { background: #f0f6ff; color: #1a2639; }
        .menu-item:hover i { color: #1a73e8; }
        .menu-item.active { background: #1a73e8; color: #ffffff; box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3); }
        .menu-item.active i { color: #ffffff; }
        .sidebar-footer { border-top: 1px solid #edf2f9; padding-top: 16px; display: flex; flex-direction: column; align-items: stretch; gap: 12px; margin-top: auto; }
        .sidebar-footer .user-row { display: flex; align-items: center; gap: 14px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(145deg, #eef2f9, #dce3ef); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 16px; color: #2d3a4f; }
        .user-info { line-height: 1.3; }
        .user-info .name { font-weight: 600; font-size: 13px; color: #1a2639; }
        .user-info .role { font-size: 12px; color: #8a9bb5; }
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #34a853; margin-right: 6px; }
        .logout-btn-sidebar { display: flex; align-items: center; justify-content: center; gap: 8px; background: #dc3545; color: #ffffff; border: none; border-radius: 60px; padding: 10px 16px; font-weight: 600; font-size: 13px; text-decoration: none; transition: all 0.2s ease; width: 100%; box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25); cursor: pointer; }
        .logout-btn-sidebar:hover { background: #c82333; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35); }
        .footer-system { text-align: center; font-size: 12px; color: #8a9bb5; padding: 16px 0 8px; border-top: 1px solid #e2e9f3; margin-top: auto; background: transparent; flex-shrink: 0; }

        @media (max-width: 820px) {
            .sidebar { display: none; }
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 10px;
        }
        .loading i {
            font-size: 24px;
            color: #1a73e8;
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-calendar-plus"></i> Cadastrar Aulas</h1>
                <p class="page-subtitle">Agende todas as aulas do curso de uma só vez</p>
            </div>
            <a href="listar_aulas.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </header>

        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?></div>
        <?php endif; ?>
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $mensagem_erro; ?></div>
        <?php endif; ?>

        <div class="card-panel">
            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> <strong>Como funciona:</strong></p>
                <p>1. Selecione um curso <strong>ativo</strong> que ainda <strong>não possui todas as aulas cadastradas</strong>.</p>
                <p>2. Preencha os dados da aula (horário, turno).</p>
                <p>3. O sistema irá <strong>verificar automaticamente</strong> a disponibilidade de <strong>todas as salas</strong> da unidade.</p>
                <p>4. Escolha uma das salas sugeridas ou selecione outra manualmente.</p>
                <p>5. O professor é <strong>opcional</strong> - pode ser definido depois.</p>
            </div>

            <div class="info-box warning">
                <p><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong></p>
                <p>• Apenas cursos <strong>ativos</strong> e com aulas <strong>pendentes</strong> são exibidos.</p>
                <p>• Cursos já concluídos ou com todas as aulas cadastradas <strong>não aparecem</strong>.</p>
                <p>• O sistema mostra <strong>todas as salas</strong> da unidade com seus detalhes.</p>
                <p>• Salas em <strong>manutenção</strong> aparecem com <strong>fundo AMARELO</strong> e não podem ser selecionadas.</p>
                <p>• Salas <strong>inativas</strong> não são exibidas.</p>
                <p>• <strong>Dias de recesso</strong> são automaticamente <strong>pulados</strong> e as aulas são <strong>empurradas</strong> para os próximos dias úteis.</p>
                <p>• <strong>Recessos são aplicados apenas aos cursos específicos selecionados.</strong></p>
            </div>

            <!-- O RESTO DO HTML PERMANECE IGUAL -->
            <form method="POST" action="" id="formCadastro">
                <div class="form-row">
                    <div class="form-group">
                        <label for="id_curso">
                            <i class="fas fa-book"></i> Curso <span class="required">*</span>
                            <span style="font-size: 12px; color: #7a8aa0; font-weight: 400;">(digite ou selecione)</span>
                        </label>
                        <select name="id_curso" id="id_curso" style="width: 100%;" required>
                            <option value="">Buscar curso...</option>
                            <?php foreach ($cursos as $curso): 
                                $dataInicio = $curso['data_inicio_curso'];
                                $hoje = new DateTime();
                                $dataInicioObj = new DateTime($dataInicio);
                                $cursoNaoIniciado = $dataInicioObj > $hoje;
                                $aulasAtivas = $curso['aulas_ativas'] ?? 0;
                                $diasLetivos = $curso['dias_letivos'] ?? 0;
                                $aulasExibicao = $cursoNaoIniciado ? 0 : $aulasAtivas;
                            ?>
                                <option value="<?php echo $curso['id_curso']; ?>"
                                    data-inicio="<?php echo $curso['data_inicio_curso'] ?? ''; ?>"
                                    data-fim="<?php echo $curso['data_fim_curso_calculada'] ?? ''; ?>"
                                    data-dias="<?php echo $curso['dias_letivos'] ?? 0; ?>"
                                    data-dias-semana="<?php echo $curso['dias_semana'] ?? ''; ?>"
                                    data-turno="<?php echo $curso['turno_curso'] ?? ''; ?>"
                                    data-aulas-cadastradas="<?php echo $aulasAtivas; ?>"
                                    data-nao-iniciado="<?php echo $cursoNaoIniciado ? 'true' : 'false'; ?>">
                                    <?php echo htmlspecialchars($curso['numero_curso'] . ' - ' . $curso['nome_curso']); ?>
                                    <?php if ($diasLetivos > 0): ?>
                                        <span style="color: #7a8aa0; font-size: 12px;">
                                            (<?php echo $aulasExibicao; ?>/<?php echo $diasLetivos; ?> aulas)
                                            <?php if ($cursoNaoIniciado): ?>
                                                <span style="color: #ffc107; font-size: 11px;">⏳ Não iniciado</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($cursos)): ?>
                            <div class="empty-cursos">
                                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                <p style="color: #28a745; font-weight: 500;">✅ Todos os cursos ativos já possuem todas as aulas cadastradas!</p>
                                <p style="font-size: 12px; color: #7a8aa0; margin-top: 8px;">Nenhum curso disponível para novo cadastro de aulas.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="id_professor">
                            <i class="fas fa-user-tie"></i> Professor 
                            <span class="optional">(opcional)</span>
                        </label>
                        <select name="id_professor" id="id_professor" style="width: 100%;">
                            <option value="">Selecione um professor (opcional)</option>
                            <?php foreach ($professores as $professor): ?>
                                <option value="<?php echo $professor['id_funcionario']; ?>">
                                    <?php echo htmlspecialchars($professor['nome_funcionario']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> Pode ser definido posteriormente
                        </small>
                    </div>
                </div>

                <div id="cursoInfo" class="curso-info">
                    <p><strong>📅 Período do Curso:</strong></p>
                    <p>• Início: <strong id="info_inicio">-</strong></p>
                    <p>• Fim: <strong id="info_fim">-</strong></p>
                    <p>• Dias Letivos: <strong id="info_dias">-</strong></p>
                    <p>• Dias da Semana: <strong id="info_dias_semana">-</strong></p>
                    <p>• Turno: <strong id="info_turno">-</strong></p>
                    <p>• Aulas já cadastradas: <strong id="info_aulas_cadastradas">-</strong></p>
                    <p>• Status: <strong id="info_status">-</strong></p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="id_sala"><i class="fas fa-door-open"></i> Sala <span class="required">*</span></label>
                        
                        <div id="mensagemSelecioneHorario" class="mensagem-horario">
                            <i class="fas fa-info-circle"></i>
                            <strong>Preencha o horário primeiro!</strong><br>
                            <span style="font-size: 13px;">Insira o horário de início e fim para verificar a disponibilidade das salas.</span>
                        </div>
                        
                        <select name="id_sala" id="id_sala" style="width: 100%; display: none;" required>
                            <option value="">Selecione uma sala</option>
                            <?php foreach ($todasSalas as $sala): ?>
                                <option value="<?php echo $sala['id_sala']; ?>">
                                    Sala <?php echo htmlspecialchars($sala['numero_sala']); ?>
                                    <?php if (!empty($sala['tipo_sala'])): ?>
                                        - <?php echo htmlspecialchars($sala['tipo_sala']); ?>
                                    <?php endif; ?>
                                    (<?php echo $sala['capacidade_sala']; ?> pessoas)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div id="salaSelecionada" class="sala-selecionada">
                            <i class="fas fa-check-circle"></i>
                            <strong>Sala selecionada:</strong>
                            <span id="nomeSalaSelecionada"></span>
                        </div>
                        
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> Selecione uma sala disponível na lista de salas sugeridas abaixo
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="turno"><i class="fas fa-sun"></i> Turno <span class="required">*</span></label>
                        <select name="turno" id="turno" required>
                            <option value="">Selecione o turno</option>
                            <option value="manha">☀️ Manhã</option>
                            <option value="tarde">☀️ Tarde</option>
                            <option value="noite">🌙 Noite</option>
                        </select>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> O turno do curso será sugerido automaticamente
                        </small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="horario_inicio"><i class="fas fa-clock"></i> Horário Início <span class="required">*</span></label>
                        <input type="time" name="horario_inicio" id="horario_inicio" required>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> Este horário será aplicado a todas as aulas
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="horario_fim"><i class="fas fa-clock"></i> Horário Fim <span class="required">*</span></label>
                        <input type="time" name="horario_fim" id="horario_fim" required>
                    </div>
                </div>

                <div id="salasSugeridas" class="salas-sugeridas">
                    <div class="salas-header">
                        <strong><i class="fas fa-building" style="color: #1a73e8;"></i> Disponibilidade de Salas</strong>
                        <span class="total-info" id="totalSalasInfo">
                            Carregando...
                        </span>
                    </div>
                    <div id="listaSalas">
                        <div class="loading-salas" id="loadingSalas" style="display: block;">
                            <i class="fas fa-spinner"></i> Verificando disponibilidade das salas...
                        </div>
                    </div>
                    <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i> 
                        <span style="color: #28a745;">✅ Verde</span> = Disponível | 
                        <span style="color: #dc3545;">❌ Vermelho</span> = Ocupada | 
                        <span style="background: #fff8e1; color: #856404; padding: 0 6px; border-radius: 4px;">🟨 Amarelo</span> = Em Manutenção (não selecionável)
                    </small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="status_aula"><i class="fas fa-circle"></i> Status</label>
                        <select name="status_aula" id="status_aula">
                            <option value="agendada" selected>📅 Agendada</option>
                        </select>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> O status só pode ser alterado na edição da aula
                        </small>
                    </div>

                    <div class="form-group">
                        <!-- Espaço vazio -->
                    </div>
                </div>

                <div class="form-group">
                    <label for="observacao"><i class="fas fa-comment"></i> Observação</label>
                    <textarea name="observacao" id="observacao" 
                              placeholder="Digite observações sobre estas aulas (opcional)"></textarea>
                </div>

                <div class="loading" id="loading">
                    <i class="fas fa-spinner"></i> Cadastrando aulas, aguarde...
                </div>

                <div class="form-actions">
                    <a href="listar_aulas.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-success" id="btnSubmit" <?php echo empty($cursos) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save"></i> Cadastrar Todas as Aulas
                    </button>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <script>
        $(document).ready(function() {
            $('#id_curso').select2({
                placeholder: 'Digite o número da turma ou nome do curso...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return 'Nenhum curso disponível para cadastro';
                    },
                    searching: function() {
                        return 'Buscando...';
                    }
                }
            });
            
            $('#id_professor').select2({
                placeholder: 'Buscar professor...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return 'Nenhum professor encontrado';
                    },
                    searching: function() {
                        return 'Buscando...';
                    }
                }
            });

            let timeoutVerificarSalas = null;

            function controlarExibicaoSala() {
                const horarioInicio = $('#horario_inicio').val();
                const horarioFim = $('#horario_fim').val();
                const turno = $('#turno').val();
                const idCurso = $('#id_curso').val();
                
                const temHorario = horarioInicio && horarioFim && turno && idCurso;
                
                if (temHorario) {
                    $('#mensagemSelecioneHorario').hide();
                    $('#id_sala').show();
                    $('#salaSelecionada').hide();
                } else {
                    $('#mensagemSelecioneHorario').show();
                    $('#id_sala').hide();
                    $('#salaSelecionada').hide();
                }
            }

            $('#id_sala').on('change', function() {
                const selectedOption = $(this).find('option:selected');
                const idSala = $(this).val();
                
                if (idSala) {
                    const nomeSala = selectedOption.text();
                    $('#nomeSalaSelecionada').text(nomeSala);
                    $('#salaSelecionada').show();
                } else {
                    $('#salaSelecionada').hide();
                }
            });

            function verificarSalasDisponiveis() {
                const idCurso = $('#id_curso').val();
                const horarioInicio = $('#horario_inicio').val();
                const horarioFim = $('#horario_fim').val();
                const turno = $('#turno').val();
                
                if (!idCurso || !horarioInicio || !horarioFim || !turno) {
                    $('#salasSugeridas').removeClass('active');
                    return;
                }
                
                $('#loadingSalas').show();
                $('#listaSalas').html('<div class="loading-salas" style="display: block;"><i class="fas fa-spinner"></i> Verificando disponibilidade das salas...</div>');
                
                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    data: {
                        ajax: 'verificar_salas',
                        id_curso: idCurso,
                        horario_inicio: horarioInicio,
                        horario_fim: horarioFim,
                        turno: turno
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingSalas').hide();
                        
                        if (response.error) {
                            $('#salasSugeridas').removeClass('active');
                            return;
                        }
                        
                        if (response.success) {
                            const salas = response.salas;
                            const totalSalas = response.total_salas;
                            const totalDisponiveis = response.total_disponiveis;
                            const totalDatas = response.total_datas;
                            const diasRecesso = response.dias_recesso || 0;
                            
                            let totalManutencao = 0;
                            salas.forEach(function(item) {
                                if (item.em_manutencao) totalManutencao++;
                            });
                            
                            let infoHtml = '';
                            infoHtml += '<span class="disponivel-count">✅ ' + totalDisponiveis + ' disponíveis</span>';
                            infoHtml += ' | ';
                            infoHtml += '<span class="indisponivel-count">❌ ' + (totalSalas - totalDisponiveis - totalManutencao) + ' ocupadas</span>';
                            if (totalManutencao > 0) {
                                infoHtml += ' | ';
                                infoHtml += '<span class="manutencao-count">🟨 ' + totalManutencao + ' em manutenção</span>';
                            }
                            infoHtml += ' | ';
                            infoHtml += '📅 ' + totalDatas + ' dias letivos';
                            if (diasRecesso > 0) {
                                infoHtml += ' | <span style="color: #ff6b00;">⏳ ' + diasRecesso + ' dias pulados por recesso</span>';
                            }
                            $('#totalSalasInfo').html(infoHtml);
                            
                            let html = '';
                            if (salas.length === 0) {
                                html = '<div style="text-align: center; padding: 20px; color: #7a8aa0;">';
                                html += '<i class="fas fa-info-circle" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>';
                                html += 'Nenhuma sala cadastrada nesta unidade.';
                                html += '</div>';
                            } else {
                                salas.forEach(function(item) {
                                    const sala = item.sala;
                                    const disponivel = item.disponivel;
                                    const emManutencao = item.em_manutencao || false;
                                    const conflitos = item.conflitos || [];
                                    
                                    const tipoSala = sala.tipo_sala || 'sala_aula';
                                    const capacidade = sala.capacidade_sala || 'N/A';
                                    const recursos = sala.recursos_sala;
                                    
                                    let recursosText = '';
                                    if (recursos && typeof recursos === 'object') {
                                        const recursosList = Object.keys(recursos).filter(key => {
                                            const value = recursos[key];
                                            return value === true || value === 1 || value === 'true' || value === '1';
                                        });
                                        if (recursosList.length > 0) {
                                            recursosText = recursosList.slice(0, 2).join(', ');
                                            if (recursosList.length > 2) {
                                                recursosText += '...';
                                            }
                                        }
                                    }
                                    
                                    let statusClass = '';
                                    let statusText = '';
                                    let statusBadgeClass = '';
                                    
                                    if (emManutencao) {
                                        statusClass = 'em-manutencao';
                                        statusText = '🟨 Em Manutenção';
                                        statusBadgeClass = 'manutencao';
                                    } else if (disponivel) {
                                        statusClass = 'disponivel';
                                        statusText = '✅ Disponível';
                                        statusBadgeClass = 'disponivel';
                                    } else {
                                        statusClass = 'indisponivel';
                                        statusText = '❌ Ocupada';
                                        statusBadgeClass = 'indisponivel';
                                    }
                                    
                                    const podeSelecionar = disponivel && !emManutencao;
                                    
                                    html += '<div class="sala-item ' + statusClass + '" data-id="' + sala.id_sala + '" data-disponivel="' + (podeSelecionar ? 'true' : 'false') + '" onclick="selecionarSala(' + sala.id_sala + ')">';
                                    html += '    <div class="info">';
                                    html += '        <div class="numero">';
                                    html += '            <i class="fas fa-door-open"></i> Sala ' + sala.numero_sala;
                                    html += '            <span style="font-size: 12px; color: #7a8aa0; margin-left: 6px;">(' + tipoSala.replace(/_/g, ' ') + ')</span>';
                                    if (emManutencao) {
                                        html += '            <span style="font-size: 12px; color: #e65100; margin-left: 8px;">🔧 MANUTENÇÃO</span>';
                                    }
                                    html += '        </div>';
                                    html += '        <div class="detalhes">';
                                    html += '            <i class="fas fa-users"></i> ' + capacidade + ' pessoas';
                                    if (recursosText) {
                                        html += ' | <i class="fas fa-tools"></i> ' + recursosText;
                                    }
                                    if (sala.descricao_sala) {
                                        html += ' | ' + sala.descricao_sala;
                                    }
                                    if (!disponivel && !emManutencao && conflitos.length > 0) {
                                        html += ' | <span style="color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Ocupado: ' + conflitos[0].horario + ' - ' + conflitos[0].curso + '</span>';
                                    }
                                    if (emManutencao) {
                                        html += ' | <span style="color: #e65100;"><i class="fas fa-tools"></i> Indisponível para agendamentos</span>';
                                    }
                                    html += '        </div>';
                                    html += '    </div>';
                                    html += '    <div class="status-badge ' + statusBadgeClass + '">' + statusText + '</div>';
                                    html += '</div>';
                                });
                            }
                            
                            $('#listaSalas').html(html);
                            $('#salasSugeridas').addClass('active');
                            
                            $('.sala-item.disponivel').on('click', function() {
                                const idSala = $(this).data('id');
                                $('#id_sala').val(idSala).trigger('change');
                                $('.sala-item').removeClass('selecionada');
                                $(this).addClass('selecionada');
                            });
                            
                            $('.sala-item.em-manutencao').on('click', function() {
                                alert('🔧 Esta sala está em MANUTENÇÃO e não pode ser selecionada para agendamentos.\n\nPor favor, aguarde a finalização da manutenção.');
                            });
                            
                            $('.sala-item.indisponivel').on('click', function() {
                                alert('❌ Esta sala está OCUPADA e não pode ser selecionada para agendamentos.\n\nVerifique a lista de salas disponíveis.');
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loadingSalas').hide();
                        $('#salasSugeridas').removeClass('active');
                    }
                });
            }

            window.selecionarSala = function(idSala) {
                const salaItem = $('.sala-item[data-id="' + idSala + '"]');
                const disponivel = salaItem.data('disponivel') === true;
                const emManutencao = salaItem.hasClass('em-manutencao');
                
                if (emManutencao) {
                    alert('🔧 Esta sala está em MANUTENÇÃO e não pode ser selecionada para agendamentos.\n\nPor favor, aguarde a finalização da manutenção.');
                    return;
                }
                
                if (!disponivel) {
                    alert('❌ Esta sala está OCUPADA e não pode ser selecionada para agendamentos.\n\nVerifique a lista de salas disponíveis.');
                    return;
                }
                
                $('#id_sala').val(idSala).trigger('change');
                $('.sala-item').removeClass('selecionada');
                salaItem.addClass('selecionada');
            };

            $('#id_curso, #horario_inicio, #horario_fim, #turno').on('change', function() {
                controlarExibicaoSala();
                clearTimeout(timeoutVerificarSalas);
                timeoutVerificarSalas = setTimeout(verificarSalasDisponiveis, 500);
            });

            $('#id_curso').on('change', function() {
                const selectedOption = $(this).find('option:selected');
                const cursoInfo = $('#cursoInfo');
                const infoInicio = $('#info_inicio');
                const infoFim = $('#info_fim');
                const infoDias = $('#info_dias');
                const infoDiasSemana = $('#info_dias_semana');
                const infoTurno = $('#info_turno');
                const infoAulasCadastradas = $('#info_aulas_cadastradas');
                const infoStatus = $('#info_status');
                
                const idCurso = $(this).val();
                
                if (idCurso && selectedOption.data('inicio')) {
                    const inicio = selectedOption.data('inicio');
                    const fim = selectedOption.data('fim');
                    const dias = selectedOption.data('dias');
                    const diasSemana = selectedOption.data('dias-semana');
                    const turno = selectedOption.data('turno');
                    const aulasCadastradas = selectedOption.data('aulas-cadastradas') || 0;
                    
                    infoInicio.text(inicio ? formatDate(inicio) : 'Não definida');
                    infoFim.text(fim ? formatDate(fim) : 'Não definida');
                    infoDias.text(dias || 'Não definido');
                    infoDiasSemana.text(diasSemana || 'Não definido');
                    infoTurno.text(turno || 'Não definido');
                    infoAulasCadastradas.text(aulasCadastradas + ' / ' + (dias || '?'));
                    infoStatus.text('✅ Em andamento');
                    
                    cursoInfo.addClass('active');
                    
                    if (turno && turno !== 'Não definido') {
                        $('#turno').val(turno).trigger('change');
                    }
                    
                    controlarExibicaoSala();
                    verificarSalasDisponiveis();
                } else {
                    cursoInfo.removeClass('active');
                    $('#salasSugeridas').removeClass('active');
                }
            });

            function formatDate(dateStr) {
                if (!dateStr || dateStr === 'Não definida') return dateStr;
                const parts = dateStr.split('-');
                return parts[2] + '/' + parts[1] + '/' + parts[0];
            }

            $('#horario_fim').on('change', function() {
                const inicio = $('#horario_inicio').val();
                const fim = $(this).val();
                
                if (inicio && fim && inicio >= fim) {
                    alert('⚠️ O horário de fim deve ser posterior ao horário de início.');
                    $(this).val('');
                    $('#salasSugeridas').removeClass('active');
                    controlarExibicaoSala();
                }
            });
            
            $('#horario_inicio').on('change', function() {
                const fim = $('#horario_fim').val();
                const inicio = $(this).val();
                
                if (inicio && fim && inicio >= fim) {
                    alert('⚠️ O horário de início deve ser anterior ao horário de fim.');
                    $('#horario_fim').val('');
                    $('#salasSugeridas').removeClass('active');
                    controlarExibicaoSala();
                }
            });

            $('#formCadastro').on('submit', function(e) {
                const btn = $('#btnSubmit');
                const loading = $('#loading');
                btn.prop('disabled', true);
                btn.html('<i class="fas fa-spinner fa-spin"></i> Cadastrando...');
                loading.show();
            });

            controlarExibicaoSala();
        });
    </script>
</body>
</html>