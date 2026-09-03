<?php
// ============================================================
// ARQUIVO: CURSOS/editar_cursos.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Exibir formulário para editar um curso
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// FUNÇÃO PARA BUSCAR DIAS DE RECESSO DA UNIDADE (MODIFICADA)
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
// FUNÇÃO PARA CALCULAR DATA DE FIM (MODIFICADA)
// ============================================================
function calcularDataFim($conn, $data_inicio, $dias_letivos, $dias_semana, $id_unidade = null, $id_cliente = null, $turno = null, $id_curso = null) {
    // Mapeamento dos dias da semana
    $diasSemanaMap = [
        'segunda' => 1, 'terca' => 2, 'quarta' => 3,
        'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
    ];
    
    // Converter dias_semana para array de números
    $diasSemanaArray = explode(',', $dias_semana);
    $diasPermitidos = [];
    foreach ($diasSemanaArray as $dia) {
        $dia = trim($dia);
        if (isset($diasSemanaMap[$dia])) {
            $diasPermitidos[] = $diasSemanaMap[$dia];
        }
    }
    
    if (empty($diasPermitidos)) {
        return null;
    }
    
    // ============================================================
    // BUSCAR DIAS DE RECESSO - FILTRANDO PELO CURSO E CLIENTE
    // ============================================================
    $diasRecesso = buscarDiasRecesso($conn, $id_unidade, $id_cliente, $id_curso);
    
    $dataAtual = new DateTime($data_inicio);
    $diasContados = 0;
    $diasPulados = [];
    $ultimaData = null;
    
    // Contar dias letivos a partir da data de início
    while ($diasContados < $dias_letivos) {
        $diaSemana = (int)$dataAtual->format('N');
        $dataStr = $dataAtual->format('Y-m-d');
        
        if (in_array($dataStr, $diasRecesso)) {
            $diasPulados[] = [
                'data' => $dataStr,
                'nome' => 'Recesso',
                'tipo' => 'recesso'
            ];
            $dataAtual->modify('+1 day');
            continue;
        }
        
        if (in_array($diaSemana, $diasPermitidos)) {
            $diasContados++;
            $ultimaData = clone $dataAtual;
        }
        
        $dataAtual->modify('+1 day');
    }
    
    if (!$ultimaData) {
        return null;
    }
    
    return [
        'data_fim' => $ultimaData->format('Y-m-d'),
        'dias_pulados' => $diasPulados,
        'total_pulados' => count($diasPulados)
    ];
}

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem editar cursos.');
    redirect('listar_cursos.php');
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
// FUNÇÃO PARA REMOVER PERMANENTEMENTE TODAS AS AULAS DO CURSO (MODIFICADA)
// ============================================================
function removerAulasDoCurso($conn, $id_curso, $id_cliente) {
    try {
        // Buscar quantas aulas serão removidas
        $sqlContar = "SELECT COUNT(*) FROM cronograma WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtContar = $conn->prepare($sqlContar);
        $stmtContar->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $totalAulas = $stmtContar->fetchColumn();
        
        // Remover todas as aulas do curso (DELETE)
        $sqlDelete = "DELETE FROM cronograma WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $aulasRemovidas = $stmtDelete->rowCount();
        
        return [
            'success' => true,
            'aulas_removidas' => $aulasRemovidas,
            'message' => "$aulasRemovidas aula(s) removida(s) permanentemente."
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao remover aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// FUNÇÃO PARA RECALCULAR DATA DE FIM (MODIFICADA)
// ============================================================
function recalcularDataFimCurso($conn, $id_curso, $id_cliente) {
    try {
        // Buscar a última data de aula que não esteja cancelada
        $sql = "SELECT MAX(data_aula) as ultima_data FROM cronograma 
                WHERE id_curso = :id_curso 
                AND id_cliente = :id_cliente
                AND status_aula NOT IN ('cancelada')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resultado || !$resultado['ultima_data']) {
            return false;
        }
        
        $ultimaData = $resultado['ultima_data'];
        
        // Buscar dados do curso
        $sqlCurso = "SELECT dias_semana, id_unidade, turno_curso FROM cursos 
                     WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtCurso = $conn->prepare($sqlCurso);
        $stmtCurso->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $curso = $stmtCurso->fetch(PDO::FETCH_ASSOC);
        
        if (!$curso) {
            return false;
        }
        
        // Buscar dias de recesso da unidade - filtrando pelo curso
        $diasRecesso = buscarDiasRecesso($conn, $curso['id_unidade'], $id_cliente, $id_curso);
        
        // Mapear dias da semana do curso
        $diasSemanaMap = [
            'segunda' => 1, 'terca' => 2, 'quarta' => 3,
            'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'domingo' => 7
        ];
        
        $diasSemanaArray = explode(',', $curso['dias_semana']);
        $diasPermitidos = [];
        foreach ($diasSemanaArray as $dia) {
            $dia = trim($dia);
            if (isset($diasSemanaMap[$dia])) {
                $diasPermitidos[] = $diasSemanaMap[$dia];
            }
        }
        
        if (empty($diasPermitidos)) {
            $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':data_fim' => $ultimaData,
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            return true;
        }
        
        // Calcular a nova data de fim
        $dataAtual = new DateTime($ultimaData);
        $diasAvancados = 0;
        
        while ($diasAvancados < 30) {
            $dataAtual->modify('+1 day');
            $diasAvancados++;
            
            $diaSemana = (int)$dataAtual->format('N');
            $dataStr = $dataAtual->format('Y-m-d');
            
            if (!in_array($diaSemana, $diasPermitidos)) {
                continue;
            }
            
            if (in_array($dataStr, $diasRecesso)) {
                continue;
            }
            
            $novaDataFim = $dataStr;
            
            $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':data_fim' => $novaDataFim,
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            
            return true;
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Erro ao recalcular data de fim do curso {$id_curso}: " . $e->getMessage());
        return false;
    }
}

$id = (int)($_GET['id'] ?? 0);

try {
    // Buscar curso (FILTRADO POR CLIENTE)
    $sql = "SELECT * FROM cursos WHERE id_curso = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $curso = $stmt->fetch();

    if (!$curso) {
        setMessage('error', 'Curso não encontrado.');
        redirect('listar_cursos.php');
    }

    // Verificar permissão do coordenador (gerente)
    if ($tipo_usuario === 'gerente' && $curso['id_unidade'] != $id_unidade_usuario) {
        setMessage('error', 'Você não tem permissão para editar este curso.');
        redirect('listar_cursos.php');
    }

    // Buscar unidades (FILTRADAS POR CLIENTE)
    if ($tipo_usuario === 'admin_cliente') {
        $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? ORDER BY nome_unidade ASC";
        $stmt_unidades = $conn->prepare($sql_unidades);
        $stmt_unidades->execute([$id_cliente]);
        $unidades = $stmt_unidades->fetchAll();
    } else {
        $sql_unidades = "SELECT id_unidade, nome_unidade FROM unidades 
                         WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente 
                         ORDER BY nome_unidade ASC";
        $stmt_unidades = $conn->prepare($sql_unidades);
        $stmt_unidades->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $unidades = $stmt_unidades->fetchAll();
    }

    // ============================================================
    // PROFESSORES - COM SELECT2 (FILTRADOS POR CLIENTE)
    // ============================================================
    $sql_docentes = "SELECT id_funcionario, nome_funcionario 
                     FROM funcionarios 
                     WHERE cargo_funcionario = 'professor' 
                     AND id_cliente = :id_cliente
                     ORDER BY nome_funcionario ASC";
    $stmt_docentes = $conn->prepare($sql_docentes);
    $stmt_docentes->execute([':id_cliente' => $id_cliente]);
    $docentes = $stmt_docentes->fetchAll();

    // Buscar tipos de sala já cadastrados (FILTRADOS POR CLIENTE)
    $sql_tipos = "SELECT DISTINCT tipo_sala_preferencial FROM cursos 
                  WHERE id_cliente = :id_cliente
                  AND tipo_sala_preferencial IS NOT NULL 
                  AND tipo_sala_preferencial != '' 
                  ORDER BY tipo_sala_preferencial ASC";
    $stmt_tipos = $conn->prepare($sql_tipos);
    $stmt_tipos->execute([':id_cliente' => $id_cliente]);
    $tipos_cursos = $stmt_tipos->fetchAll(PDO::FETCH_COLUMN);

    $sql_tipos_salas = "SELECT DISTINCT tipo_sala FROM salas 
                        WHERE id_cliente = :id_cliente
                        ORDER BY tipo_sala ASC";
    $stmt_tipos_salas = $conn->prepare($sql_tipos_salas);
    $stmt_tipos_salas->execute([':id_cliente' => $id_cliente]);
    $tipos_salas = $stmt_tipos_salas->fetchAll(PDO::FETCH_COLUMN);

    $tipos_sala = array_unique(array_merge($tipos_cursos, $tipos_salas));
    sort($tipos_sala);

    // ============================================================
    // CONTAR AULAS DO CURSO (FILTRADAS POR CLIENTE)
    // ============================================================
    $sqlContarAulas = "SELECT COUNT(*) as total_aulas
                       FROM cronograma 
                       WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
    $stmtContar = $conn->prepare($sqlContarAulas);
    $stmtContar->execute([
        ':id_curso' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $infoAulas = $stmtContar->fetch(PDO::FETCH_ASSOC);

    $totalAulas = $infoAulas['total_aulas'] ?? 0;

    // ============================================================
    // PROCESSAR EDIÇÃO DO CURSO (via POST)
    // ============================================================
    $mensagem_sucesso = '';
    $mensagem_erro = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_editar'])) {
        $id_curso = (int)($_POST['id_curso'] ?? 0);
        
        // Coletar todos os dados do formulário
        $numero_curso = trim($_POST['numero_curso'] ?? '');
        $nome_curso = trim($_POST['nome_curso'] ?? '');
        $id_unidade = (int)($_POST['id_unidade'] ?? 0);
        $id_docente = !empty($_POST['id_docente']) ? (int)$_POST['id_docente'] : null;
        $carga_horaria_curso = (int)($_POST['carga_horaria_curso'] ?? 0);
        $horas_por_dia = (int)($_POST['horas_por_dia'] ?? 0);
        $tipo_sala_preferencial = trim($_POST['tipo_sala_preferencial'] ?? '');
        $data_inicio_curso = $_POST['data_inicio_curso'] ?? '';
        $turno_curso = $_POST['turno_curso'] ?? '';
        $dias_semana = isset($_POST['dias_semana']) ? implode(',', $_POST['dias_semana']) : '';
        $tipo_curso = $_POST['tipo_curso'] ?? '';
        $status_curso = $_POST['status_curso'] ?? 'ativo';
        
        // Validações básicas
        $erros = [];
        if (empty($numero_curso)) $erros[] = 'Número do curso é obrigatório.';
        if (empty($nome_curso)) $erros[] = 'Nome do curso é obrigatório.';
        if (empty($id_unidade)) $erros[] = 'Unidade é obrigatória.';
        if (empty($data_inicio_curso)) $erros[] = 'Data de início é obrigatória.';
        if (empty($turno_curso)) $erros[] = 'Turno é obrigatório.';
        if (empty($dias_semana)) $erros[] = 'Pelo menos um dia da semana deve ser selecionado.';
        if (empty($tipo_curso)) $erros[] = 'Tipo de curso é obrigatório.';
        if ($carga_horaria_curso <= 0) $erros[] = 'Carga horária deve ser maior que zero.';
        if ($horas_por_dia <= 0) $erros[] = 'Horas por dia deve ser maior que zero.';
        
        if (empty($erros)) {
            try {
                $conn->beginTransaction();
                
                // ============================================================
                // BUSCAR DADOS ATUAIS DO CURSO PARA COMPARAÇÃO
                // ============================================================
                $sqlCursoAtual = "SELECT 
                                    status_curso, 
                                    data_fim_curso_calculada,
                                    data_inicio_curso as data_inicio_atual,
                                    dias_letivos,
                                    dias_semana as dias_semana_atual
                                  FROM cursos 
                                  WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                $stmtAtual = $conn->prepare($sqlCursoAtual);
                $stmtAtual->execute([
                    ':id_curso' => $id_curso,
                    ':id_cliente' => $id_cliente
                ]);
                $cursoAtual = $stmtAtual->fetch(PDO::FETCH_ASSOC);
                
                $statusAtual = $cursoAtual['status_curso'] ?? '';
                $dataFimAtual = $cursoAtual['data_fim_curso_calculada'] ?? null;
                $dataInicioAtual = $cursoAtual['data_inicio_atual'] ?? '';
                $diasLetivosAtuais = $cursoAtual['dias_letivos'] ?? 0;
                $diasSemanaAtuais = $cursoAtual['dias_semana_atual'] ?? '';
                
                // ============================================================
                // VERIFICAR SE A DATA DE INÍCIO FOI ALTERADA
                // ============================================================
                $dataInicioAlterada = ($data_inicio_curso !== $dataInicioAtual);
                
                // ============================================================
                // VERIFICAR SE HÁ AULAS CADASTRADAS
                // ============================================================
                $sqlVerificarAulas = "SELECT COUNT(*) as total FROM cronograma 
                                      WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                $stmtVerificarAulas = $conn->prepare($sqlVerificarAulas);
                $stmtVerificarAulas->execute([
                    ':id_curso' => $id_curso,
                    ':id_cliente' => $id_cliente
                ]);
                $totalAulasAtual = $stmtVerificarAulas->fetchColumn();
                
                // ============================================================
                // SE A DATA DE INÍCIO FOI ALTERADA E HÁ AULAS, REMOVER AS AULAS
                // ============================================================
                $aulasRemovidas = 0;
                $dataInicioAlteradaComAulas = false;
                
                if ($dataInicioAlterada && $totalAulasAtual > 0 && $status_curso !== 'inativo') {
                    $resultadoRemocao = removerAulasDoCurso($conn, $id_curso, $id_cliente);
                    
                    if (!$resultadoRemocao['success']) {
                        throw new Exception($resultadoRemocao['message']);
                    }
                    
                    $aulasRemovidas = $resultadoRemocao['aulas_removidas'];
                    $dataInicioAlteradaComAulas = true;
                }
                
                // ============================================================
                // CALCULAR DIAS LETIVOS E DATA DE FIM
                // ============================================================
                $dias_letivos = ceil($carga_horaria_curso / $horas_por_dia);
                
                $resultadoDataFim = calcularDataFim($conn, $data_inicio_curso, $dias_letivos, $dias_semana, $id_unidade, $id_cliente, $turno_curso, $id_curso);
                
                if ($resultadoDataFim && isset($resultadoDataFim['data_fim'])) {
                    $data_fim_calculada = $resultadoDataFim['data_fim'];
                    $total_pulados = $resultadoDataFim['total_pulados'] ?? 0;
                } else {
                    $dataObj = new DateTime($data_inicio_curso);
                    $dataObj->modify('+30 days');
                    $data_fim_calculada = $dataObj->format('Y-m-d');
                    $total_pulados = 0;
                }
                
                // ============================================================
                // ATUALIZAR DADOS BÁSICOS DO CURSO
                // ============================================================
                $sqlUpdate = "UPDATE cursos SET 
                                numero_curso = :numero_curso,
                                nome_curso = :nome_curso,
                                id_unidade = :id_unidade,
                                id_docente = :id_docente,
                                carga_horaria_curso = :carga_horaria_curso,
                                horas_por_dia = :horas_por_dia,
                                tipo_sala_preferencial = :tipo_sala_preferencial,
                                data_inicio_curso = :data_inicio_curso,
                                data_fim_curso_calculada = :data_fim_curso_calculada,
                                dias_letivos = :dias_letivos,
                                turno_curso = :turno_curso,
                                dias_semana = :dias_semana,
                                tipo_curso = :tipo_curso
                            WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':numero_curso' => $numero_curso,
                    ':nome_curso' => $nome_curso,
                    ':id_unidade' => $id_unidade,
                    ':id_docente' => $id_docente,
                    ':carga_horaria_curso' => $carga_horaria_curso,
                    ':horas_por_dia' => $horas_por_dia,
                    ':tipo_sala_preferencial' => $tipo_sala_preferencial,
                    ':data_inicio_curso' => $data_inicio_curso,
                    ':data_fim_curso_calculada' => $data_fim_calculada,
                    ':dias_letivos' => $dias_letivos,
                    ':turno_curso' => $turno_curso,
                    ':dias_semana' => $dias_semana,
                    ':tipo_curso' => $tipo_curso,
                    ':id_curso' => $id_curso,
                    ':id_cliente' => $id_cliente
                ]);
                
                // ============================================================
                // MONTAR MENSAGEM DE SUCESSO
                // ============================================================
                $mensagem = "✅ Curso atualizado com sucesso!";
                
                if ($total_pulados > 0) {
                    $mensagem .= " <br>📅 <strong>{$total_pulados} dia(s)</strong> foram pulados por serem recessos/feriados específicos deste curso.";
                }
                
                if ($dataInicioAlteradaComAulas) {
                    $mensagem .= " <br>📅 <strong>Data de início alterada!</strong> As <strong>{$aulasRemovidas} aula(s)</strong> existentes foram <strong>removidas permanentemente</strong>.";
                    $mensagem .= " <br>🔄 <strong>Nova data de fim:</strong> " . date('d/m/Y', strtotime($data_fim_calculada));
                    $mensagem .= " <br>📝 <strong>Por favor, remaneje as aulas do curso.</strong>";
                } elseif ($dataInicioAlterada) {
                    $mensagem .= " <br>📅 Nova data de fim: <strong>" . date('d/m/Y', strtotime($data_fim_calculada)) . "</strong>";
                }
                
                // ============================================================
                // TRATAR MUDANÇA DE STATUS
                // ============================================================
                $statusMudou = ($status_curso !== $statusAtual);
                
                if ($statusMudou) {
                    if ($status_curso === 'inativo' && $statusAtual !== 'inativo') {
                        $resultadoRemocao = removerAulasDoCurso($conn, $id_curso, $id_cliente);
                        
                        if (!$resultadoRemocao['success']) {
                            throw new Exception($resultadoRemocao['message']);
                        }
                        
                        $sqlStatus = "UPDATE cursos SET status_curso = 'inativo' WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                        $stmtStatus = $conn->prepare($sqlStatus);
                        $stmtStatus->execute([
                            ':id_curso' => $id_curso,
                            ':id_cliente' => $id_cliente
                        ]);
                        
                        if ($dataFimAtual) {
                            $_SESSION['data_fim_backup_' . $id_curso] = $dataFimAtual;
                        }
                        
                        $mensagem .= " <br>⛔ Curso inativado. " . $resultadoRemocao['message'];
                        
                    } elseif ($status_curso === 'ativo' && $statusAtual === 'inativo') {
                        $dataFimRecuperada = $_SESSION['data_fim_backup_' . $id_curso] ?? null;
                        
                        if (!$dataFimRecuperada) {
                            $dataFimRecuperada = $data_fim_calculada;
                        }
                        
                        if ($dataFimRecuperada) {
                            $sqlStatus = "UPDATE cursos 
                                          SET status_curso = 'ativo', 
                                              data_fim_curso_calculada = :data_fim 
                                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                            $stmtStatus = $conn->prepare($sqlStatus);
                            $stmtStatus->execute([
                                ':data_fim' => $dataFimRecuperada,
                                ':id_curso' => $id_curso,
                                ':id_cliente' => $id_cliente
                            ]);
                            
                            unset($_SESSION['data_fim_backup_' . $id_curso]);
                            
                            $mensagem .= " <br>🟢 Curso reativado! Data de fim: " . date('d/m/Y', strtotime($dataFimRecuperada));
                            $mensagem .= " <br>📝 <strong>Lembre-se de cadastrar novas aulas para este curso.</strong>";
                        } else {
                            $sqlStatus = "UPDATE cursos SET status_curso = 'ativo' WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                            $stmtStatus = $conn->prepare($sqlStatus);
                            $stmtStatus->execute([
                                ':id_curso' => $id_curso,
                                ':id_cliente' => $id_cliente
                            ]);
                            
                            unset($_SESSION['data_fim_backup_' . $id_curso]);
                            
                            $mensagem .= " <br>🟢 Curso reativado, mas a data de fim não pôde ser recuperada.";
                        }
                        
                    } else {
                        $sqlStatus = "UPDATE cursos SET status_curso = :status 
                                     WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
                        $stmtStatus = $conn->prepare($sqlStatus);
                        $stmtStatus->execute([
                            ':status' => $status_curso,
                            ':id_curso' => $id_curso,
                            ':id_cliente' => $id_cliente
                        ]);
                        
                        $mensagem .= " Status alterado para " . 
                                   ($status_curso === 'ativo' ? 'Ativo' : 'Concluído') . ".";
                    }
                }
                
                setMessage('success', $mensagem);
                $conn->commit();
                
                redirect('editar_cursos.php?id=' . $id_curso . '&success=1');
                
            } catch (Exception $e) {
                if (isset($conn) && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                setMessage('error', '❌ ' . $e->getMessage());
                redirect('editar_cursos.php?id=' . $id_curso);
            } catch (PDOException $e) {
                if (isset($conn) && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                setMessage('error', '❌ Erro ao processar: ' . $e->getMessage());
                redirect('editar_cursos.php?id=' . $id_curso);
            }
        } else {
            setMessage('error', '⚠️ ' . implode(' ', $erros));
            redirect('editar_cursos.php?id=' . $id_curso);
        }
    }

    // Se veio com sucesso, mostrar mensagem
    if (isset($_GET['success'])) {
        $message = getMessage();
        if ($message && $message['tipo'] === 'success') {
            $mensagem_sucesso = $message['mensagem'];
        }
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao carregar dados.');
    redirect('listar_cursos.php');
}

// Mensagens da sessão
$message = getMessage();
$erro = '';
if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
}

$titulo = 'Editar Curso - Gerenciador de Salas';

// Buscar nome da unidade do coordenador
$nomeUnidadeCoordenador = '';
if ($tipo_usuario === 'gerente' && $id_unidade_usuario) {
    try {
        $sql_nome = "SELECT nome_unidade FROM unidades 
                     WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
        $stmt_nome = $conn->prepare($sql_nome);
        $stmt_nome->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $nomeUnidadeCoordenador = $stmt_nome->fetchColumn();
    } catch (PDOException $e) {
        $nomeUnidadeCoordenador = 'Unidade não encontrada';
    }
}
?>
<?php include_once __DIR__ . '/../INCLUDES/head.php'; ?>
<?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

<!-- Select2 CSS e JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Ajustes para Select2 */
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

    /* Estilo para unidade bloqueada */
    .unidade-bloqueada {
        padding: 10px 14px;
        background: #f0f4fb;
        border: 1px solid #e2e9f3;
        border-radius: 8px;
        color: #1a2639;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .unidade-bloqueada i {
        color: #1a73e8;
    }
    .unidade-bloqueada .badge-coordenador {
        background: #e3f2fd;
        color: #0d47a1;
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }

    /* Alertas customizados */
    .alert-warning-strong {
        background: #fff3cd;
        border-color: #ffc107;
        color: #856404;
        border-left: 4px solid #ffc107;
    }
    .alert-danger-strong {
        background: #ffe9e9;
        border-color: #dc3545;
        color: #b33a3a;
        border-left: 4px solid #dc3545;
    }
    .alert-info-strong {
        background: #e3f2fd;
        border-color: #1a73e8;
        color: #0d47a1;
        border-left: 4px solid #1a73e8;
    }
    .alert-success-strong {
        background: #d4edda;
        border-color: #28a745;
        color: #155724;
        border-left: 4px solid #28a745;
    }

    /* Estilos do formulário */
    .card-panel {
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #ebf0f8;
        padding: 28px 32px;
        margin-bottom: 20px;
        max-width: 900px;
        width: 100%;
        align-self: center;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    }

    .form-group {
        margin-bottom: 18px;
    }
    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #2d3a4f;
        margin-bottom: 4px;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 10px 14px;
        border: 1.5px solid #e2e9f3;
        border-radius: 8px;
        font-size: 14px;
        background: #fafcff;
        transition: all 0.2s;
        font-family: 'Inter', sans-serif;
        color: #1a2639;
    }
    .form-group input:focus, .form-group select:focus {
        border-color: #1a73e8;
        box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
        outline: none;
    }
    .form-group input[readonly] {
        background: #f0f4fb;
        color: #6c7a8e;
        cursor: not-allowed;
    }
    .form-group small {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        color: #7a8aa0;
    }
    .form-group small i {
        margin-right: 4px;
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
        font-family: 'Inter', sans-serif;
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

    .btn-outline {
        background: transparent;
        color: #1a2639;
        border: 1px solid #d8e0ec;
    }
    .btn-outline:hover {
        background: #f0f4fb;
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
        max-width: 900px;
        width: 100%;
        align-self: center;
    }
    .alert-danger {
        background: #ffe9e9;
        color: #b33a3a;
        border: 1px solid #ffd6d6;
    }
    .alert i {
        font-size: 18px;
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

    .main {
        flex: 1;
        padding: 28px 36px 20px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ============================================================
       SIDEBAR (mantido do original)
    ============================================================ */
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

    @media (max-width: 820px) {
        .sidebar { display: none; }
        .main { padding: 16px; }
        .form-row { grid-template-columns: 1fr; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; justify-content: center; }
        .card-panel { padding: 20px; }
    }
</style>

<main class="main">
    <header class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-edit"></i> Editar Curso</h1>
            <p class="page-subtitle">Atualize os dados do curso</p>
        </div>
        <div class="header-actions">
            <a href="listar_cursos.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
    </header>

    <?php if ($mensagem_erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensagem_erro); ?></div>
    <?php endif; ?>

    <?php if ($mensagem_sucesso): ?>
        <div class="alert alert-success-strong"><i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

    <!-- ============================================================
    ALERTA SOBRE MUDANÇA DE DATA DE INÍCIO
    ============================================================ -->
    <?php if ($totalAulas > 0 && $curso['status_curso'] !== 'inativo'): ?>
        <div class="alert alert-warning-strong" style="margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
            <div>
                <strong>⚠️ ATENÇÃO!</strong> Este curso possui <strong><?php echo $totalAulas; ?> aula(s)</strong> cadastradas.
                <br>
                <strong>Se você alterar a data de início, TODAS as aulas serão REMOVIDAS PERMANENTEMENTE</strong> e você precisará remanejar as aulas novamente.
                <br>
                <span style="color: #dc3545; font-weight: bold;">Esta ação NÃO pode ser desfeita!</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================================
    ALERTA SOBRE INATIVAÇÃO
    ============================================================ -->
    <?php if ($curso['status_curso'] !== 'inativo' && $totalAulas > 0): ?>
        <div class="alert alert-danger-strong" style="margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
            <div>
                <strong>⚠️ ATENÇÃO!</strong> Este curso possui <strong><?php echo $totalAulas; ?> aula(s)</strong> cadastradas.
                <br>
                Ao alterar o status para <strong>"INATIVO"</strong>, <strong>TODAS as aulas serão REMOVIDAS PERMANENTEMENTE</strong> do banco de dados.
                <br>
                <strong style="color: #dc3545;">Esta ação NÃO pode ser desfeita!</strong>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================================
    ALERTA PARA CURSO INATIVO
    ============================================================ -->
    <?php if ($curso['status_curso'] === 'inativo'): ?>
        <div class="alert alert-info-strong" style="margin-bottom: 20px;">
            <i class="fas fa-info-circle" style="font-size: 20px;"></i>
            <div>
                <strong>📌 Curso Inativo</strong>
                <br>Este curso está atualmente <strong>inativo</strong>. Todas as aulas foram removidas permanentemente.
                <br>Para reativá-lo, altere o status para <strong>"Ativo"</strong> e cadastre novas aulas.
                <br><strong style="color: #0d47a1;">A data de fim será restaurada automaticamente ao reativar.</strong>
            </div>
        </div>
    <?php endif; ?>

    <div class="card-panel">
        <form action="editar_cursos.php?id=<?php echo $id; ?>" method="POST" id="formEditarCurso">
            <input type="hidden" name="acao_editar" value="1">
            <input type="hidden" name="id_curso" value="<?php echo $curso['id_curso']; ?>">
            <input type="hidden" name="id_cliente" value="<?php echo $id_cliente; ?>">

            <!-- Número do Curso -->
            <div class="form-group">
                <label for="numero_curso"><i class="fas fa-hashtag"></i> Número do Curso *</label>
                <input type="text" name="numero_curso" id="numero_curso" value="<?php echo htmlspecialchars($curso['numero_curso']); ?>" required>
            </div>

            <!-- Nome do Curso -->
            <div class="form-group">
                <label for="nome_curso"><i class="fas fa-book"></i> Nome do Curso *</label>
                <input type="text" name="nome_curso" id="nome_curso" value="<?php echo htmlspecialchars($curso['nome_curso']); ?>" required>
            </div>

            <!-- ============================================================
            UNIDADE - BLOQUEADA PARA COORDENADOR (GERENTE)
            ============================================================ -->
            <div class="form-group">
                <label for="id_unidade"><i class="fas fa-building"></i> Unidade *</label>

                <?php if ($tipo_usuario === 'admin_cliente'): ?>
                    <select name="id_unidade" id="id_unidade" required>
                        <option value="">Selecione a unidade</option>
                        <?php foreach ($unidades as $unidade): ?>
                            <option value="<?php echo $unidade['id_unidade']; ?>" 
                                <?php echo $unidade['id_unidade'] == $curso['id_unidade'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($unidade['nome_unidade']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="unidade-bloqueada">
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($nomeUnidadeCoordenador ?: 'Unidade não definida'); ?>
                        <span class="badge-coordenador">Coordenador</span>
                    </div>
                    <input type="hidden" name="id_unidade" value="<?php echo $id_unidade_usuario; ?>">
                    <small style="color: #7a8aa0; font-size: 12px;">
                        <i class="fas fa-info-circle"></i> 
                        A unidade está bloqueada pois você é coordenador. Apenas administradores podem alterar a unidade.
                    </small>
                <?php endif; ?>
            </div>

            <!-- ============================================================
            PROFESSOR RESPONSÁVEL - COM AUTOCOMPLETE (SELECT2)
            ============================================================ -->
            <div class="form-group">
                <label for="id_docente"><i class="fas fa-user-tie"></i> Professor Responsável</label>
                <select name="id_docente" id="id_docente" style="width: 100%;">
                    <option value="">Selecione ou digite o nome do professor</option>
                    <?php foreach ($docentes as $docente): ?>
                        <option value="<?php echo $docente['id_funcionario']; ?>" 
                            <?php echo ($docente['id_funcionario'] == $curso['id_docente']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($docente['nome_funcionario']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Digite para buscar um professor ou deixe em branco.</small>
            </div>

            <!-- Carga Horária -->
            <div class="form-row">
                <div class="form-group">
                    <label for="carga_horaria_curso"><i class="fas fa-clock"></i> Carga Horária Total (horas) *</label>
                    <input type="number" name="carga_horaria_curso" id="carga_horaria_curso" value="<?php echo $curso['carga_horaria_curso']; ?>" min="1" required>
                </div>
                <div class="form-group">
                    <label for="horas_por_dia"><i class="fas fa-hourglass-half"></i> Horas por Dia *</label>
                    <input type="number" name="horas_por_dia" id="horas_por_dia" value="<?php echo $curso['horas_por_dia'] ?? 4; ?>" min="1" required>
                </div>
            </div>

            <!-- Percentual de Conclusão (readonly) -->
            <div class="form-group">
                <label for="percentual_conclusao"><i class="fas fa-chart-line"></i> Percentual de Conclusão (%)</label>
                <input type="number" name="percentual_conclusao" id="percentual_conclusao" value="<?php echo $curso['percentual_conclusao'] ?? 0; ?>" step="0.01" readonly>
                <small><i class="fas fa-info-circle"></i> Atualize pelo módulo de progresso.</small>
            </div>

            <!-- Tipo de Sala Preferencial -->
            <div class="form-group">
                <label for="tipo_sala_preferencial"><i class="fas fa-door-open"></i> Tipo de Sala Preferencial</label>
                <input type="text" 
                       name="tipo_sala_preferencial" 
                       id="tipo_sala_preferencial" 
                       list="lista_tipos_sala"
                       placeholder="Digite ou selecione um tipo de sala..."
                       style="width: 100%; padding: 8px 12px; border: 1px solid #e2e9f3; border-radius: 6px; font-size: 14px;"
                       value="<?php echo htmlspecialchars($curso['tipo_sala_preferencial'] ?? ''); ?>">
                <datalist id="lista_tipos_sala">
                    <?php foreach ($tipos_sala as $tipo): ?>
                        <option value="<?php echo htmlspecialchars($tipo); ?>">
                    <?php endforeach; ?>
                </datalist>
                <small><i class="fas fa-info-circle"></i> Digite para criar um novo tipo ou selecione um existente.</small>
            </div>

            <!-- Data de Início e Turno -->
            <div class="form-row">
                <div class="form-group">
                    <label for="data_inicio_curso"><i class="fas fa-calendar-plus"></i> Data de Início *</label>
                    <input type="date" name="data_inicio_curso" id="data_inicio_curso" value="<?php echo $curso['data_inicio_curso']; ?>" required>
                    <small style="color: #dc3545; display: block; margin-top: 4px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>ATENÇÃO:</strong> Alterar a data de início removerá TODAS as aulas existentes!
                    </small>
                </div>
                <div class="form-group">
                    <label for="turno_curso"><i class="fas fa-sun"></i> Turno *</label>
                    <select name="turno_curso" id="turno_curso" required>
                        <option value="">Selecione o turno</option>
                        <option value="manha" <?php echo $curso['turno_curso'] == 'manha' ? 'selected' : ''; ?>>☀️ Manhã</option>
                        <option value="tarde" <?php echo $curso['turno_curso'] == 'tarde' ? 'selected' : ''; ?>>☀️ Tarde</option>
                        <option value="noite" <?php echo $curso['turno_curso'] == 'noite' ? 'selected' : ''; ?>>🌙 Noite</option>
                        <option value="integral" <?php echo $curso['turno_curso'] == 'integral' ? 'selected' : ''; ?>>🔄 Integral</option>
                    </select>
                </div>
            </div>

            <!-- Dias da Semana -->
            <div class="form-group">
                <label><i class="fas fa-calendar-week"></i> Dias da Semana *</label>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <?php
                    $dias_selecionados = explode(',', $curso['dias_semana']);
                    $dias_lista = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
                    $dias_labels = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                    foreach ($dias_lista as $index => $dia):
                        $checked = in_array($dia, $dias_selecionados) ? 'checked' : '';
                    ?>
                        <label style="font-weight: 400; font-size: 14px; display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" name="dias_semana[]" value="<?php echo $dia; ?>" <?php echo $checked; ?>> <?php echo $dias_labels[$index]; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small><i class="fas fa-info-circle"></i> Selecione os dias da semana em que o curso ocorrerá.</small>
            </div>

            <!-- Tipo de Curso -->
            <div class="form-group">
                <label for="tipo_curso"><i class="fas fa-tag"></i> Tipo de Curso *</label>
                <select name="tipo_curso" id="tipo_curso" required>
                    <option value="">Selecione o tipo</option>
                    <option value="curso_tecnico" <?php echo $curso['tipo_curso'] == 'curso_tecnico' ? 'selected' : ''; ?>>📘 Curso Técnico</option>
                    <option value="curso_agil" <?php echo $curso['tipo_curso'] == 'curso_agil' ? 'selected' : ''; ?>>⚡ Curso Ágil</option>
                    <option value="pos_graduacao" <?php echo $curso['tipo_curso'] == 'pos_graduacao' ? 'selected' : ''; ?>>🎓 Pós-graduação</option>
                </select>
            </div>

            <!-- Status do Curso -->
            <div class="form-group">
                <label for="status_curso"><i class="fas fa-circle"></i> Status do Curso</label>
                <select name="status_curso" id="status_curso" required>
                    <option value="ativo" <?php echo ($curso['status_curso'] ?? 'ativo') == 'ativo' ? 'selected' : ''; ?>>✅ Ativo</option>
                    <option value="inativo" <?php echo ($curso['status_curso'] ?? 'ativo') == 'inativo' ? 'selected' : ''; ?>>❌ Inativo</option>
                    <option value="concluido" <?php echo ($curso['status_curso'] ?? 'ativo') == 'concluido' ? 'selected' : ''; ?>>📌 Concluído</option>
                </select>
                
                <!-- Data de Fim (exibição apenas para informação) -->
                <?php if (!empty($curso['data_fim_curso_calculada'])): ?>
                    <small style="color: #1a73e8; display: block; margin-top: 4px;">
                        <i class="fas fa-calendar-check"></i> 
                        Data de Fim atual: <strong><?php echo date('d/m/Y', strtotime($curso['data_fim_curso_calculada'])); ?></strong>
                        <?php if ($curso['status_curso'] === 'inativo'): ?>
                            <span style="color: #856404;">(será restaurada ao reativar)</span>
                        <?php endif; ?>
                    </small>
                <?php else: ?>
                    <small style="color: #856404; display: block; margin-top: 4px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Data de Fim não definida. Ao reativar, será calculada automaticamente.
                    </small>
                <?php endif; ?>
                
                <!-- Mensagens informativas -->
                <?php if ($totalAulas > 0 && $curso['status_curso'] !== 'inativo'): ?>
                    <small style="color: #dc3545; display: block; margin-top: 4px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Ao selecionar <strong>"Inativo"</strong>, todas as <strong><?php echo $totalAulas; ?> aula(s)</strong> serão <strong>REMOVIDAS PERMANENTEMENTE</strong>.
                    </small>
                <?php endif; ?>
                
                <?php if ($curso['status_curso'] === 'inativo'): ?>
                    <small style="color: #0d47a1; display: block; margin-top: 4px;">
                        <i class="fas fa-info-circle"></i> 
                        Curso atualmente inativo. Todas as aulas foram removidas permanentemente.
                        <br>A data de fim será restaurada automaticamente ao reativar.
                    </small>
                <?php endif; ?>
                
                <?php if ($tipo_usuario === 'gerente'): ?>
                    <small style="color: #1a73e8; display: block; margin-top: 4px;">
                        <i class="fas fa-check-circle"></i> 
                        Você tem permissão para inativar este curso. Ao inativar, todas as aulas serão permanentemente removidas.
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="btnSalvar">
                    <i class="fas fa-save"></i> Salvar Alterações
                </button>
                <a href="listar_cursos.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancelar</a>
            </div>
        </form>
    </div>

    <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
</main>

<script>
    $(document).ready(function() {
        // ============================================================
        // SELECT2 PARA PROFESSOR (AUTOCOMPLETE)
        // ============================================================
        $('#id_docente').select2({
            placeholder: 'Digite o nome do professor...',
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

        // ============================================================
        // VALIDAR MUDANÇA DE DATA DE INÍCIO COM AULAS EXISTENTES
        // ============================================================
        var dataInicioOriginal = '<?php echo $curso['data_inicio_curso']; ?>';
        var totalAulas = <?php echo $totalAulas; ?>;
        var statusAtual = '<?php echo $curso['status_curso']; ?>';

        document.getElementById('data_inicio_curso').addEventListener('change', function() {
            var novaDataInicio = this.value;
            
            if (novaDataInicio !== dataInicioOriginal && totalAulas > 0 && statusAtual !== 'inativo') {
                var confirmar = confirm(
                    '📅 ATENÇÃO!\n\n' +
                    'Você está alterando a data de início do curso de\n' +
                    dataInicioOriginal + ' para ' + novaDataInicio + '.\n\n' +
                    'Este curso possui ' + totalAulas + ' aula(s) cadastrada(s).\n\n' +
                    '⚠️ AO ALTERAR A DATA DE INÍCIO:\n' +
                    '• TODAS as ' + totalAulas + ' aulas serão REMOVIDAS PERMANENTEMENTE\n' +
                    '• Você precisará cadastrar as aulas novamente\n' +
                    '• A nova data de fim será calculada automaticamente\n\n' +
                    '❌ Esta ação NÃO pode ser desfeita!\n\n' +
                    'Deseja continuar?'
                );
                
                if (!confirmar) {
                    this.value = dataInicioOriginal;
                    return;
                }
            }
        });

        // ============================================================
        // CONFIRMAR MUDANÇA DE STATUS PARA INATIVO
        // ============================================================
        document.getElementById('status_curso').addEventListener('change', function() {
            var statusAtualSelect = '<?php echo $curso['status_curso']; ?>';
            var novoStatus = this.value;
            var totalAulasSelect = <?php echo $totalAulas; ?>;

            if (novoStatus === 'inativo' && statusAtualSelect !== 'inativo' && totalAulasSelect > 0) {
                var confirmar = confirm(
                    '⚠️ ATENÇÃO!\n\n' +
                    'Este curso possui ' + totalAulasSelect + ' aula(s) cadastrada(s).\n\n' +
                    'Ao alterar o status para "INATIVO", TODAS as aulas serão REMOVIDAS PERMANENTEMENTE do banco de dados.\n\n' +
                    'As salas serão automaticamente liberadas.\n\n' +
                    '❌ Esta ação NÃO pode ser desfeita!\n\n' +
                    'Deseja continuar?'
                );

                if (!confirmar) {
                    this.value = statusAtualSelect;
                    return;
                }
            }

            if (novoStatus === 'ativo' && statusAtualSelect === 'inativo') {
                var confirmar = confirm(
                    '📌 Reativar Curso\n\n' +
                    'Ao reativar este curso:\n' +
                    '• A data de fim será restaurada automaticamente\n' +
                    '• Você precisará cadastrar novas aulas (as aulas anteriores foram removidas)\n\n' +
                    'Deseja continuar?'
                );

                if (!confirmar) {
                    this.value = statusAtualSelect;
                    return;
                }
            }
        });

        // ============================================================
        // PREVENIR DUPLO CLIQUE
        // ============================================================
        document.getElementById('formEditarCurso').addEventListener('submit', function(e) {
            var btn = document.getElementById('btnSalvar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        });
    });
</script>
</body>
</html>