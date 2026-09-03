<?php
// ============================================================
// ARQUIVO: SALAS/editar_sala.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Editar salas com manutenção integrada
// VERSÃO CORRIGIDA - Com Cancelar Manutenção
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem editar salas.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// VARIÁVEIS DE PERMISSÃO (NOVO SISTEMA)
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

$id_sala = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_sala <= 0) {
    setMessage('error', 'ID da sala inválido.');
    redirect('listar_salas.php');
}

// ============================================================
// FUNÇÃO PARA VERIFICAR E ATUALIZAR STATUS DE MANUTENÇÃO (MODIFICADA)
// ============================================================
function atualizarStatusManutencao($conn, $id_sala, $id_cliente) {
    try {
        $hoje = date('Y-m-d');
        
        $sql = "SELECT id_manutencao, data_inicio, data_fim, status 
                FROM manutencoes 
                WHERE id_sala = :id_sala 
                AND id_cliente = :id_cliente
                AND status = 'em_andamento'
                AND :hoje BETWEEN data_inicio AND data_fim";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente,
            ':hoje' => $hoje
        ]);
        $manutencaoAtiva = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($manutencaoAtiva) {
            $sqlUpdate = "UPDATE salas SET status_sala = 'manutencao' WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([':id_sala' => $id_sala]);
            return true;
        } else {
            $sqlVerificar = "SELECT status_sala FROM salas WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
            $stmtVerificar = $conn->prepare($sqlVerificar);
            $stmtVerificar->execute([':id_sala' => $id_sala]);
            $statusAtual = $stmtVerificar->fetchColumn();
            
            if ($statusAtual === 'manutencao') {
                $sqlUpdate = "UPDATE salas SET status_sala = 'disponivel' WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([':id_sala' => $id_sala]);
            }
            return false;
        }
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// BUSCAR DADOS ATUAIS DA SALA (FILTRADOS POR CLIENTE)
// ============================================================
try {
    atualizarStatusManutencao($conn, $id_sala, $id_cliente);
    
    $stmt = $conn->prepare("SELECT * FROM salas WHERE id_sala = :id AND id_cliente = :id_cliente");
    $stmt->execute([
        ':id' => $id_sala,
        ':id_cliente' => $id_cliente
    ]);
    $sala = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sala) {
        setMessage('error', 'Sala não encontrada.');
        redirect('listar_salas.php');
    }
} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar dados da sala: ' . $e->getMessage());
    redirect('listar_salas.php');
}

// ============================================================
// BUSCAR LISTA DE UNIDADES (FILTRADAS POR CLIENTE)
// ============================================================
try {
    $sqlUnidades = "SELECT id_unidade, nome_unidade FROM unidades WHERE id_cliente = ? AND status_unidade = 'ativo' ORDER BY nome_unidade";
    $stmtUnidades = $conn->prepare($sqlUnidades);
    $stmtUnidades->execute([$id_cliente]);
    $unidades = $stmtUnidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $unidades = [];
}

// ============================================================
// BUSCAR TIPOS DE SALA EXISTENTES (FILTRADOS POR CLIENTE)
// ============================================================
try {
    $sqlTipos = "SELECT DISTINCT tipo_sala FROM salas WHERE id_cliente = :id_cliente AND tipo_sala IS NOT NULL AND tipo_sala != '' ORDER BY tipo_sala ASC";
    $stmtTipos = $conn->prepare($sqlTipos);
    $stmtTipos->execute([':id_cliente' => $id_cliente]);
    $tiposExistentes = $stmtTipos->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $tiposExistentes = [];
}

// ============================================================
// BUSCAR MANUTENÇÕES DA SALA (FILTRADAS POR CLIENTE)
// ============================================================
try {
    $sqlManut = "SELECT * FROM manutencoes WHERE id_sala = :id_sala AND id_cliente = :id_cliente ORDER BY data_inicio DESC";
    $stmtManut = $conn->prepare($sqlManut);
    $stmtManut->execute([
        ':id_sala' => $id_sala,
        ':id_cliente' => $id_cliente
    ]);
    $manutencoes = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $manutencoes = [];
}

// ============================================================
// FUNÇÃO PARA MARCAR AULAS COMO AGUARDANDO (MODIFICADA)
// ============================================================
function marcarAulasAguardando($conn, $id_sala, $id_cliente, $data_inicio, $data_fim) {
    try {
        $sqlBuscar = "SELECT id_aula, data_aula, id_curso, horario_inicio, horario_fim 
                      FROM cronograma 
                      WHERE id_sala = :id_sala 
                      AND id_cliente = :id_cliente
                      AND data_aula BETWEEN :data_inicio AND :data_fim
                      AND status_aula IN ('agendada', 'remarcada')";
        
        $stmtBuscar = $conn->prepare($sqlBuscar);
        $stmtBuscar->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        $aulas = $stmtBuscar->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($aulas)) {
            return ['success' => true, 'message' => 'Nenhuma aula encontrada no período da manutenção.', 'total' => 0];
        }
        
        $totalAulas = count($aulas);
        $aulasAtualizadas = 0;
        
        foreach ($aulas as $aula) {
            $sqlUpdate = "UPDATE cronograma SET 
                            status_aula = 'aguardando_remarcacao',
                            observacao = CONCAT(IFNULL(observacao, ''), ' | Sala em manutenção - Aguardando remarcação')
                          WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id_aula' => $aula['id_aula'],
                ':id_cliente' => $id_cliente
            ]);
            $aulasAtualizadas++;
        }
        
        return [
            'success' => true,
            'message' => "$aulasAtualizadas aula(s) marcadas como 'Aguardando Remarcação'",
            'total' => $aulasAtualizadas
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao marcar aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// FUNÇÃO PARA REVERTER AULAS DE AGUARDANDO PARA AGENDADA (MODIFICADA)
// ============================================================
function reverterAulasAguardando($conn, $id_sala, $id_cliente, $data_inicio, $data_fim) {
    try {
        $sqlBuscar = "SELECT id_aula, data_aula, id_curso, horario_inicio, horario_fim 
                      FROM cronograma 
                      WHERE id_sala = :id_sala 
                      AND id_cliente = :id_cliente
                      AND data_aula BETWEEN :data_inicio AND :data_fim
                      AND status_aula = 'aguardando_remarcacao'";
        
        $stmtBuscar = $conn->prepare($sqlBuscar);
        $stmtBuscar->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente,
            ':data_inicio' => $data_inicio,
            ':data_fim' => $data_fim
        ]);
        $aulas = $stmtBuscar->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($aulas)) {
            return ['success' => true, 'message' => 'Nenhuma aula para reverter.', 'total' => 0];
        }
        
        $totalAulas = count($aulas);
        $aulasAtualizadas = 0;
        
        foreach ($aulas as $aula) {
            $sqlUpdate = "UPDATE cronograma SET 
                            status_aula = 'agendada',
                            observacao = REPLACE(observacao, ' | Sala em manutenção - Aguardando remarcação', '')
                          WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id_aula' => $aula['id_aula'],
                ':id_cliente' => $id_cliente
            ]);
            $aulasAtualizadas++;
        }
        
        return [
            'success' => true,
            'message' => "$aulasAtualizadas aula(s) revertidas para 'Agendada'",
            'total' => $aulasAtualizadas
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao reverter aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// FUNÇÃO PARA ATUALIZAR AULAS QUANDO SALA FICA INATIVA (MODIFICADA)
// ============================================================
function atualizarAulasParaAguardandoRemarcacao($conn, $id_sala, $id_cliente, $status_anterior, $status_novo) {
    try {
        if ($status_novo === 'inativa' && $status_anterior !== 'inativa') {
            $sqlBuscar = "SELECT id_aula, data_aula, id_curso, turno, horario_inicio, horario_fim 
                          FROM cronograma 
                          WHERE id_sala = :id_sala 
                          AND id_cliente = :id_cliente
                          AND status_aula IN ('agendada', 'remarcada', 'aguardando_remarcacao')
                          AND data_aula >= CURDATE()";
            $stmtBuscar = $conn->prepare($sqlBuscar);
            $stmtBuscar->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $aulas = $stmtBuscar->fetchAll(PDO::FETCH_ASSOC);

            $totalAulas = count($aulas);
            $aulasAtualizadas = 0;

            if ($totalAulas > 0) {
                $sqlUpdate = "UPDATE cronograma SET 
                                status_aula = 'aguardando_remarcacao',
                                observacao = CONCAT(IFNULL(observacao, ''), ' | Sala inativada - Aguardando remarcação')
                              WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);

                foreach ($aulas as $aula) {
                    $stmtUpdate->execute([
                        ':id_aula' => $aula['id_aula'],
                        ':id_cliente' => $id_cliente
                    ]);
                    $aulasAtualizadas++;
                }

                return [
                    'success' => true,
                    'total' => $totalAulas,
                    'atualizadas' => $aulasAtualizadas,
                    'message' => "$aulasAtualizadas aulas marcadas como 'Aguardando Remarcação'"
                ];
            } else {
                return ['success' => true, 'total' => 0, 'atualizadas' => 0, 'message' => 'Nenhuma aula futura encontrada.'];
            }
        }
        return ['success' => true, 'message' => 'Nenhuma alteração necessária.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao atualizar aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// FUNÇÃO PARA RECALCULAR DATA DE FIM DO CURSO (MODIFICADA)
// ============================================================
function recalcularDataFimCurso($conn, $id_curso, $id_cliente) {
    try {
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

        if ($resultado && $resultado['ultima_data']) {
            $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':data_fim' => $resultado['ultima_data'],
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// PROCESSAR EDIÇÃO DA SALA
// ============================================================
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['acao_manutencao']) && !isset($_POST['acao_finalizar_manutencao']) && !isset($_POST['acao_cancelar_manutencao'])) {
    if ($tipo_usuario === 'gerente') {
        $id_unidade = $id_unidade_usuario;
    } else {
        $id_unidade = (int)($_POST['id_unidade'] ?? 0);
    }
    
    $numero       = trim($_POST['numero'] ?? '');
    $andar        = trim($_POST['andar'] ?? '');
    $capacidade   = trim($_POST['capacidade'] ?? '');
    $tipo         = trim($_POST['tipo'] ?? '');
    $status_novo  = $_POST['status'] ?? 'disponivel';
    $descricao    = trim($_POST['descricao'] ?? '');
    $recursos     = trim($_POST['recursos'] ?? '');

    $status_anterior = $sala['status_sala'];

    if (empty($id_unidade) || empty($numero) || empty($andar) || empty($capacidade) || empty($tipo)) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (!is_numeric($capacidade) || $capacidade <= 0) {
        $erro = 'Capacidade deve ser um número positivo.';
    }

    if (empty($erro)) {
        try {
            $conn->beginTransaction();

            $check = $conn->prepare("SELECT COUNT(*) FROM salas WHERE numero_sala = :numero AND id_cliente = :id_cliente AND id_sala != :id");
            $check->execute([
                ':numero' => $numero,
                ':id_cliente' => $id_cliente,
                ':id' => $id_sala
            ]);
            if ($check->fetchColumn() > 0) {
                $erro = 'Já existe uma sala cadastrada com este número nesta organização.';
                $conn->rollBack();
            } else {
                $sql = "UPDATE salas SET 
                            id_unidade = :id_unidade,
                            numero_sala = :numero,
                            andar_sala = :andar,
                            capacidade_sala = :capacidade,
                            tipo_sala = :tipo,
                            status_sala = :status,
                            descricao_sala = :descricao,
                            recursos_sala = :recursos
                        WHERE id_sala = :id AND id_cliente = :id_cliente";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':id_unidade' => $id_unidade,
                    ':numero'     => $numero,
                    ':andar'      => $andar,
                    ':capacidade' => $capacidade,
                    ':tipo'       => $tipo,
                    ':status'     => $status_novo,
                    ':descricao'  => $descricao,
                    ':recursos'   => $recursos ?: null,
                    ':id'         => $id_sala,
                    ':id_cliente' => $id_cliente
                ]);

                if ($status_novo === 'inativa' && $status_anterior !== 'inativa') {
                    $resultadoAulas = atualizarAulasParaAguardandoRemarcacao($conn, $id_sala, $id_cliente, $status_anterior, $status_novo);
                    if (!$resultadoAulas['success']) {
                        throw new Exception($resultadoAulas['message']);
                    }
                    $mensagemInativar = ' ' . ($resultadoAulas['message'] ?? '');
                } else {
                    $mensagemInativar = '';
                }

                if ($status_anterior === 'inativa' && $status_novo !== 'inativa') {
                    $sqlReativar = "UPDATE cronograma SET 
                                    status_aula = 'agendada',
                                    observacao = CONCAT(IFNULL(observacao, ''), ' | Sala reativada - Aula reagendada')
                                  WHERE id_sala = :id_sala 
                                  AND id_cliente = :id_cliente
                                  AND status_aula = 'aguardando_remarcacao'";
                    $stmtReativar = $conn->prepare($sqlReativar);
                    $stmtReativar->execute([
                        ':id_sala' => $id_sala,
                        ':id_cliente' => $id_cliente
                    ]);
                    $aulasReativadas = $stmtReativar->rowCount();
                    $mensagemReativar = " $aulasReativadas aulas foram reagendadas.";
                } else {
                    $mensagemReativar = '';
                }

                $conn->commit();

                $sucesso = '✅ Sala atualizada com sucesso!';
                if (!empty($mensagemInativar)) {
                    $sucesso .= ' ' . $mensagemInativar;
                }
                if (!empty($mensagemReativar)) {
                    $sucesso .= ' ' . $mensagemReativar;
                }

                $sala = array_merge($sala, [
                    'id_unidade'     => $id_unidade,
                    'numero_sala'    => $numero,
                    'andar_sala'     => $andar,
                    'capacidade_sala'=> $capacidade,
                    'tipo_sala'      => $tipo,
                    'status_sala'    => $status_novo,
                    'descricao_sala' => $descricao,
                    'recursos_sala'  => $recursos
                ]);

                $stmtManut = $conn->prepare("SELECT * FROM manutencoes WHERE id_sala = :id_sala AND id_cliente = :id_cliente ORDER BY data_inicio DESC");
                $stmtManut->execute([
                    ':id_sala' => $id_sala,
                    ':id_cliente' => $id_cliente
                ]);
                $manutencoes = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = 'Erro ao atualizar sala: ' . $e->getMessage();
        }
    }
}

// ============================================================
// PROCESSAR MANUTENÇÃO (MODIFICADO)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_manutencao'])) {
    $turnos = $_POST['turnos_manut'] ?? [];
    $motivo = trim($_POST['motivo_manut'] ?? '');
    $data_inicio = $_POST['data_inicio_manut'] ?? '';
    $data_fim = $_POST['data_fim_manut'] ?? '';

    if (empty($data_inicio) || empty($data_fim)) {
        $erro = 'Selecione as datas de início e fim da manutenção.';
    } elseif (empty($turnos)) {
        $erro = 'Selecione pelo menos um turno para a manutenção.';
    } elseif (empty($motivo)) {
        $erro = 'Descreva o motivo da manutenção.';
    } else {
        try {
            $conn->beginTransaction();

            $turno_str = implode(',', $turnos);
            
            $sqlInsert = "INSERT INTO manutencoes (
                            id_sala, id_cliente, data_inicio, data_fim, turno, motivo, status
                        ) VALUES (
                            :id_sala, :id_cliente, :data_inicio, :data_fim, :turno, :motivo, 'em_andamento'
                        )";
            $stmtInsert = $conn->prepare($sqlInsert);
            $stmtInsert->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim,
                ':turno' => $turno_str,
                ':motivo' => $motivo
            ]);

            $resultado = marcarAulasAguardando($conn, $id_sala, $id_cliente, $data_inicio, $data_fim);

            if (!$resultado['success']) {
                throw new Exception($resultado['message']);
            }
            $mensagemAulas = ' ' . $resultado['message'];

            $conn->commit();
            
            $hoje = date('Y-m-d');
            $statusAtualSala = 'disponivel';
            
            if ($data_inicio <= $hoje && $data_fim >= $hoje) {
                $sqlUpdateSala = "UPDATE salas SET status_sala = 'manutencao' WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
                $stmtUpdateSala = $conn->prepare($sqlUpdateSala);
                $stmtUpdateSala->execute([
                    ':id_sala' => $id_sala,
                    ':id_cliente' => $id_cliente
                ]);
                $statusAtualSala = 'manutencao';
                $sala['status_sala'] = 'manutencao';
            }
            
            $sucesso = "✅ Manutenção registrada com sucesso! Período: $data_inicio a $data_fim.$mensagemAulas";
            if ($statusAtualSala === 'manutencao') {
                $sucesso .= "<br>🔧 A sala está em <strong>MANUTENÇÃO</strong> (período já iniciado).";
            } else {
                $sucesso .= "<br>📌 A sala permanecerá <strong>DISPONÍVEL</strong> até o início da manutenção em " . date('d/m/Y', strtotime($data_inicio)) . ".";
            }
            $sucesso .= "<br>🔄 Quando a manutenção for concluída, clique em <strong>'Finalizar Manutenção'</strong> para liberar a sala.";

            $stmtManut = $conn->prepare("SELECT * FROM manutencoes WHERE id_sala = :id_sala AND id_cliente = :id_cliente ORDER BY data_inicio DESC");
            $stmtManut->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $manutencoes = $stmtManut->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ Erro ao registrar manutenção: ' . $e->getMessage();
        }
    }
}

// ============================================================
// PROCESSAR FINALIZAÇÃO DA MANUTENÇÃO (MODIFICADO)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_finalizar_manutencao'])) {
    $id_manutencao = (int)($_POST['id_manutencao'] ?? 0);
    
    if ($id_manutencao <= 0) {
        $erro = 'ID da manutenção inválido.';
    } else {
        try {
            $conn->beginTransaction();
            
            $sqlContarAulas = "SELECT COUNT(*) as total FROM cronograma 
                               WHERE id_sala = :id_sala 
                               AND id_cliente = :id_cliente
                               AND status_aula = 'aguardando_remarcacao'";
            $stmtContar = $conn->prepare($sqlContarAulas);
            $stmtContar->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $totalAulasPendentes = (int)$stmtContar->fetchColumn();
            
            $sqlUpdateManut = "UPDATE manutencoes SET status = 'concluida' 
                               WHERE id_manutencao = :id_manutencao AND id_cliente = :id_cliente";
            $stmtUpdateManut = $conn->prepare($sqlUpdateManut);
            $stmtUpdateManut->execute([
                ':id_manutencao' => $id_manutencao,
                ':id_cliente' => $id_cliente
            ]);
            
            $sqlUpdateSala = "UPDATE salas SET status_sala = 'disponivel' 
                              WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
            $stmtUpdateSala = $conn->prepare($sqlUpdateSala);
            $stmtUpdateSala->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            
            $conn->commit();
            
            $sucesso = "✅ Manutenção finalizada com sucesso! A sala agora está disponível para novos agendamentos.";
            
            if ($totalAulasPendentes > 0) {
                $sucesso .= "<br><br>📌 <strong>Atenção:</strong> Existem <strong>{$totalAulasPendentes} aula(s)</strong> com status <strong>'Aguardando Remarcação'</strong> para esta sala.";
                $sucesso .= "<br>🔄 Estas aulas precisam ser <strong>remanejadas manualmente</strong> para outra sala.";
                $sucesso .= "<br><a href='../cronograma/listar_aulas_aguardando.php?id_sala={$id_sala}' style='color: #1a73e8; font-weight: 600; text-decoration: underline;'>
                                <i class='fas fa-arrow-right'></i> Visualizar aulas pendentes
                            </a>";
            }
            
            $sala['status_sala'] = 'disponivel';
            
            $stmtManut = $conn->prepare("SELECT * FROM manutencoes WHERE id_sala = :id_sala AND id_cliente = :id_cliente ORDER BY data_inicio DESC");
            $stmtManut->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $manutencoes = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ Erro ao finalizar manutenção: ' . $e->getMessage();
        }
    }
}

// ============================================================
// PROCESSAR CANCELAMENTO DA MANUTENÇÃO (MODIFICADO)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_cancelar_manutencao'])) {
    $id_manutencao = (int)($_POST['id_manutencao'] ?? 0);
    
    if ($id_manutencao <= 0) {
        $erro = 'ID da manutenção inválido.';
    } else {
        try {
            $conn->beginTransaction();
            
            $sqlBuscar = "SELECT data_inicio, data_fim, id_sala FROM manutencoes 
                          WHERE id_manutencao = :id_manutencao AND id_cliente = :id_cliente";
            $stmtBuscar = $conn->prepare($sqlBuscar);
            $stmtBuscar->execute([
                ':id_manutencao' => $id_manutencao,
                ':id_cliente' => $id_cliente
            ]);
            $manutencao = $stmtBuscar->fetch(PDO::FETCH_ASSOC);
            
            if (!$manutencao) {
                throw new Exception('Manutenção não encontrada.');
            }
            
            $data_inicio = $manutencao['data_inicio'];
            $data_fim = $manutencao['data_fim'];
            $id_sala = $manutencao['id_sala'];
            
            $resultadoReverter = reverterAulasAguardando($conn, $id_sala, $id_cliente, $data_inicio, $data_fim);
            
            if (!$resultadoReverter['success']) {
                throw new Exception($resultadoReverter['message']);
            }
            $mensagemReverter = ' ' . $resultadoReverter['message'];
            
            $sqlDelete = "DELETE FROM manutencoes WHERE id_manutencao = :id_manutencao AND id_cliente = :id_cliente";
            $stmtDelete = $conn->prepare($sqlDelete);
            $stmtDelete->execute([
                ':id_manutencao' => $id_manutencao,
                ':id_cliente' => $id_cliente
            ]);
            
            $sqlVerificar = "SELECT COUNT(*) FROM manutencoes 
                             WHERE id_sala = :id_sala 
                             AND id_cliente = :id_cliente
                             AND status = 'em_andamento'
                             AND :hoje BETWEEN data_inicio AND data_fim";
            $stmtVerificar = $conn->prepare($sqlVerificar);
            $stmtVerificar->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente,
                ':hoje' => date('Y-m-d')
            ]);
            $temOutraManutencao = (int)$stmtVerificar->fetchColumn() > 0;
            
            if (!$temOutraManutencao) {
                $sqlUpdateSala = "UPDATE salas SET status_sala = 'disponivel' 
                                  WHERE id_sala = :id_sala AND id_cliente = :id_cliente";
                $stmtUpdateSala = $conn->prepare($sqlUpdateSala);
                $stmtUpdateSala->execute([
                    ':id_sala' => $id_sala,
                    ':id_cliente' => $id_cliente
                ]);
                $sala['status_sala'] = 'disponivel';
            }
            
            $conn->commit();
            
            $sucesso = "✅ Manutenção cancelada com sucesso!$mensagemReverter";
            $sucesso .= "<br>📌 As aulas foram <strong>revertidas</strong> para o status <strong>'Agendada'</strong>.";
            
            $stmtManut = $conn->prepare("SELECT * FROM manutencoes WHERE id_sala = :id_sala AND id_cliente = :id_cliente ORDER BY data_inicio DESC");
            $stmtManut->execute([
                ':id_sala' => $id_sala,
                ':id_cliente' => $id_cliente
            ]);
            $manutencoes = $stmtManut->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
            $erro = '❌ Erro ao cancelar manutenção: ' . $e->getMessage();
        }
    }
}

// ============================================================
// CONTAR AULAS AFETADAS (FILTRADAS POR CLIENTE)
// ============================================================
$totalAulasAfetadas = 0;
if ($sala['status_sala'] !== 'inativa') {
    try {
        $stmtAulas = $conn->prepare("SELECT COUNT(*) FROM cronograma 
                                    WHERE id_sala = :id_sala 
                                    AND id_cliente = :id_cliente
                                    AND status_aula IN ('agendada', 'remarcada')
                                    AND data_aula >= CURDATE()");
        $stmtAulas->execute([
            ':id_sala' => $id_sala,
            ':id_cliente' => $id_cliente
        ]);
        $totalAulasAfetadas = (int)$stmtAulas->fetchColumn();
    } catch (PDOException $e) {
        $totalAulasAfetadas = 0;
    }
}

// ============================================================
// VERIFICAR SE EXISTE MANUTENÇÃO FUTURA (FILTRADA POR CLIENTE)
// ============================================================
$manutencaoFutura = false;
$dataProximaManutencao = null;
try {
    $hoje = date('Y-m-d');
    $sqlFutura = "SELECT data_inicio FROM manutencoes 
                  WHERE id_sala = :id_sala 
                  AND id_cliente = :id_cliente
                  AND status = 'em_andamento'
                  AND data_inicio > :hoje
                  ORDER BY data_inicio ASC LIMIT 1";
    $stmtFutura = $conn->prepare($sqlFutura);
    $stmtFutura->execute([
        ':id_sala' => $id_sala,
        ':id_cliente' => $id_cliente,
        ':hoje' => $hoje
    ]);
    $resultado = $stmtFutura->fetch(PDO::FETCH_ASSOC);
    if ($resultado) {
        $manutencaoFutura = true;
        $dataProximaManutencao = $resultado['data_inicio'];
    }
} catch (PDOException $e) {
    // Ignorar erro
}

$turnosLabels = [
    'manha' => 'Manhã (07:00 - 12:00)',
    'tarde' => 'Tarde (13:00 - 18:00)',
    'noite' => 'Noite (19:00 - 22:00)',
    'integral' => 'Integral (Todos os turnos)'
];

$titulo = 'Editar Sala - Gerenciador de Salas';

$nomeUnidadeAtual = '';
foreach ($unidades as $uni) {
    if ($uni['id_unidade'] == $sala['id_unidade']) {
        $nomeUnidadeAtual = $uni['nome_unidade'];
        break;
    }
}

// Mensagens da sessão
$message = getMessage();
if ($message && $message['tipo'] === 'error') {
    $erro = $message['mensagem'];
} elseif ($message && $message['tipo'] === 'success') {
    $sucesso = $message['mensagem'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>

    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        /* ============================================================
        CSS COMPLETO MANTIDO
        ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4fb;
            display: flex;
            height: 100vh;
            overflow: hidden;
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

        .logo-area {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f4fb;
        }

        .logo-icon {
            background: linear-gradient(145deg, #1a73e8, #0d47a1);
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
            box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3);
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #1a2639;
        }
        .logo-text span { color: #1a73e8; }
        .logo-text small {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #7a8aa0;
            margin-top: 2px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .menu-label {
            font-size: 11px;
            font-weight: 600;
            color: #9aabbf;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px 6px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 16px;
            border-radius: 10px;
            color: #5a6a7e;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.15s ease;
            text-decoration: none;
        }
        .menu-item i { width: 20px; font-size: 16px; color: #8a9bb5; transition: color 0.15s; }
        .menu-item:hover { background: #f0f6ff; color: #1a2639; }
        .menu-item:hover i { color: #1a73e8; }
        .menu-item.active { background: #1a73e8; color: #ffffff; box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3); }
        .menu-item.active i { color: #ffffff; }

        .sidebar-footer {
            border-top: 1px solid #edf2f9;
            padding-top: 16px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            margin-top: auto;
        }

        .sidebar-footer .user-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(145deg, #eef2f9, #dce3ef);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            color: #2d3a4f;
        }

        .user-info { line-height: 1.3; }
        .user-info .name { font-weight: 600; font-size: 13px; color: #1a2639; }
        .user-info .role { font-size: 12px; color: #8a9bb5; }
        .user-info .cliente {
            font-size: 11px;
            color: #1a73e8;
            font-weight: 500;
            display: block;
            margin-top: 2px;
        }

        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #34a853;
            margin-right: 6px;
        }

        .logout-btn-sidebar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #dc3545;
            color: #ffffff;
            border: none;
            border-radius: 60px;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s ease;
            width: 100%;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
            cursor: pointer;
        }
        .logout-btn-sidebar:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
        }

        .footer-system {
            text-align: center;
            font-size: 12px;
            color: #8a9bb5;
            padding: 16px 0 8px;
            border-top: 1px solid #e2e9f3;
            margin-top: auto;
            background: transparent;
            flex-shrink: 0;
        }

        @media (max-width: 820px) {
            .sidebar { display: none; }
        }

        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
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
            margin-bottom: 6px;
        }
        .page-title i {
            color: #1a73e8;
            margin-right: 10px;
        }
        .page-title strong {
            color: #007bff;
            font-size: 1.4em;
        }
        .page-subtitle {
            font-size: 14px;
            color: #7a8aa0;
            margin-bottom: 0;
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

        .btn-primary {
            background: #1a73e8;
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
        }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }

        .btn-outline {
            background: transparent;
            color: #1a2639;
            border: 1px solid #d8e0ec;
        }
        .btn-outline:hover { background: #f0f4fb; }

        .btn-danger {
            background: #dc3545;
            color: #ffffff;
            border: none;
        }
        .btn-danger:hover { background: #c82333; }

        .btn-warning {
            background: #ffc107;
            color: #212529;
            border: none;
        }
        .btn-warning:hover { background: #e0a800; transform: scale(1.02); }

        .btn-success {
            background: #28a745;
            color: #ffffff;
            border: none;
        }
        .btn-success:hover { background: #218838; }

        .btn-sm { padding: 6px 14px; font-size: 12px; }
        
        .btn-cancel {
            background: #ff6b6b;
            color: #ffffff;
            border: none;
        }
        .btn-cancel:hover { background: #e55a5a; transform: scale(1.02); }

        .card-panel {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 30px;
            max-width: 700px;
            width: 100%;
            margin: 0 auto;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #1a2639;
            margin-bottom: 6px;
        }
        .form-group label .required { color: #dc3545; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: #fafcff;
            transition: border-color 0.2s;
            color: #1a2639;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #1a73e8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group small {
            display: block;
            font-size: 12px;
            color: #7a8aa0;
            margin-top: 4px;
        }
        .form-group small i { color: #1a73e8; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f0f4fb;
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
        .alert-danger { background: #ffe9e9; color: #b33a3a; border: 1px solid #ffd6d6; }
        .alert-success { background: #e6f7e9; color: #1e8546; border: 1px solid #c8f0cf; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .alert-info { background: #e3f2fd; color: #0d47a1; border: 1px solid #b8d4f0; }
        .alert i { font-size: 18px; }

        .unidade-disabled {
            padding: 10px 14px;
            background: #f0f4fb;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            color: #6c7a8e;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .unidade-disabled i { color: #1a73e8; }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }

        .modal-content {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f0f4fb;
        }
        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #0e1a2b;
        }
        .modal-header .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #7a8aa0;
            transition: color 0.2s;
            padding: 0 8px;
        }
        .modal-header .close-btn:hover { color: #dc3545; }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 8px 0;
        }
        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #1a2639;
            cursor: pointer;
            padding: 6px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            transition: all 0.2s;
            background: #fafcff;
        }
        .checkbox-group label:hover { background: #f0f7ff; border-color: #1a73e8; }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #1a73e8;
        }
        .checkbox-group label.selecionado {
            background: #e3f2fd;
            border-color: #1a73e8;
        }

        .aviso-manutencao {
            background: #fff8e1;
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .aviso-manutencao .icone {
            font-size: 28px;
            color: #ff9800;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .aviso-manutencao .conteudo {
            flex: 1;
        }
        .aviso-manutencao .conteudo .titulo {
            font-weight: 700;
            font-size: 15px;
            color: #e65100;
        }
        .aviso-manutencao .conteudo .descricao {
            font-size: 13px;
            color: #5a6a7e;
            margin-top: 4px;
            line-height: 1.5;
        }
        .aviso-manutencao .conteudo .descricao strong {
            color: #0e1a2b;
        }
        .aviso-manutencao .conteudo .lista {
            margin-top: 6px;
            padding-left: 20px;
            font-size: 13px;
            color: #5a6a7e;
        }
        .aviso-manutencao .conteudo .lista li {
            margin-bottom: 2px;
        }

        .manutencoes-section {
            max-width: 700px;
            width: 100%;
            margin: 20px auto 0;
        }
        .manutencoes-section h3 {
            font-size: 16px;
            color: #0e1a2b;
            margin-bottom: 12px;
        }

        .manutencao-item {
            background: #f8faff;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 8px;
            border: 1px solid #e2e9f3;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .manutencao-item .info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .manutencao-item .info .periodo {
            font-weight: 600;
            color: #0e1a2b;
            font-size: 14px;
        }
        .manutencao-item .info .detalhes {
            font-size: 12px;
            color: #7a8aa0;
        }
        .manutencao-item .status-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 60px;
        }
        .status-agendada { background: #e3f2fd; color: #0d47a1; }
        .status-em_andamento { background: #fff3cd; color: #856404; }
        .status-concluida { background: #e6f7e9; color: #1e8546; }
        .status-futura { background: #e8f0fe; color: #1a73e8; }

        .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #e6f7e9; color: #1e8546; }
        .badge-warning { background: #fff2e0; color: #b86a1f; }
        .badge-danger { background: #ffe9e9; color: #b33a3a; }
        .badge-info { background: #e3f2fd; color: #0d47a1; }

        @media (max-width: 640px) {
            .main { padding: 16px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
            .card-panel { padding: 20px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { justify-content: center; }
            .modal-content { padding: 20px; margin: 10px; }
            .manutencao-item { flex-direction: column; align-items: flex-start; }
            .aviso-manutencao { flex-direction: column; }
        }

        .menu-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1000;
            background: #1a73e8;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
            transition: background 0.2s;
        }
        .menu-toggle:hover { background: #1557b0; }
        .menu-toggle i { font-size: 24px; }

        @media (max-width: 820px) {
            .sidebar { display: none; }
            .menu-toggle { display: block; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-edit"></i> 
                    Editar Sala - 
                    <strong><?php echo htmlspecialchars($sala['numero_sala']); ?></strong>
                </h1>
                <p class="page-subtitle">Altere os dados da sala ou registre manutenções</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="listar_salas.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </header>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $sucesso; ?></div>
        <?php endif; ?>

        <!-- AVISO DE MANUTENÇÃO FUTURA -->
        <?php if ($manutencaoFutura && $sala['status_sala'] !== 'manutencao'): ?>
            <div class="alert alert-info" style="max-width: 700px; margin: 0 auto 20px;">
                <i class="fas fa-calendar-alt"></i>
                <div>
                    <strong>📅 Manutenção agendada para <?php echo date('d/m/Y', strtotime($dataProximaManutencao)); ?></strong>
                    <br>A sala permanecerá <strong>DISPONÍVEL</strong> até o início da manutenção.
                    <br>Durante o período da manutenção, a sala ficará com status <strong>"Em Manutenção"</strong> automaticamente.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($sala['status_sala'] === 'manutencao'): ?>
            <div class="alert alert-warning" style="max-width: 700px; margin: 0 auto 20px;">
                <i class="fas fa-tools"></i>
                <div>
                    <strong>🔧 Sala em Manutenção</strong>
                    <br>Esta sala está atualmente em manutenção e <strong>NÃO</strong> pode receber novos agendamentos.
                    <br>Para liberar a sala, clique em <strong>"Finalizar Manutenção"</strong> no histórico abaixo.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($sala['status_sala'] !== 'inativa' && $sala['status_sala'] !== 'manutencao' && $totalAulasAfetadas > 0): ?>
            <div class="alert alert-warning" style="max-width: 700px; margin: 0 auto 20px;">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Atenção!</strong> Esta sala possui <strong><?php echo $totalAulasAfetadas; ?> aula(s) futura(s) agendada(s)</strong>.
                    Ao marcar a sala como <strong>"Inativa"</strong>, todas essas aulas serão marcadas como <strong>"Aguardando Remarcação"</strong>.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($sala['status_sala'] === 'inativa'): ?>
            <div class="alert alert-warning" style="max-width: 700px; margin: 0 auto 20px;">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Sala Inativa</strong>
                    <br>Todas as aulas futuras foram marcadas como <strong>"Aguardando Remarcação"</strong>.
                    Ao reativar, as aulas voltarão ao status <strong>"Agendada"</strong>.
                </div>
            </div>
        <?php endif; ?>

        <!-- FORMULÁRIO DE EDIÇÃO -->
        <div class="card-panel">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="id_unidade">Unidade <span class="required">*</span></label>
                    <?php if ($tipo_usuario === 'admin_cliente'): ?>
                        <select name="id_unidade" id="id_unidade" required>
                            <option value="">Selecione a unidade</option>
                            <?php foreach ($unidades as $uni): ?>
                                <option value="<?php echo $uni['id_unidade']; ?>" 
                                    <?php echo ($uni['id_unidade'] == $sala['id_unidade']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($uni['nome_unidade']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="unidade-disabled">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($nomeUnidadeAtual); ?>
                            <input type="hidden" name="id_unidade" value="<?php echo $sala['id_unidade']; ?>">
                        </div>
                        <small><i class="fas fa-lock"></i> Unidade travada de acordo com seu vínculo como coordenador.</small>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="numero">Número da Sala <span class="required">*</span></label>
                        <input type="text" name="numero" id="numero" 
                               value="<?php echo htmlspecialchars($sala['numero_sala']); ?>" 
                               placeholder="Ex: 201" required>
                    </div>
                    <div class="form-group">
                        <label for="andar">Andar <span class="required">*</span></label>
                        <input type="text" name="andar" id="andar" 
                               value="<?php echo htmlspecialchars($sala['andar_sala']); ?>" 
                               placeholder="Ex: 2" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="capacidade">Capacidade (pessoas) <span class="required">*</span></label>
                        <input type="number" name="capacidade" id="capacidade" 
                               value="<?php echo htmlspecialchars($sala['capacidade_sala']); ?>" 
                               placeholder="Ex: 30" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="tipo">Tipo da Sala <span class="required">*</span></label>
                        <input type="text" name="tipo" id="tipo" 
                               list="tipos_list" 
                               value="<?php echo htmlspecialchars($sala['tipo_sala']); ?>" 
                               placeholder="Digite ou selecione um tipo" 
                               autocomplete="off" required>
                        <datalist id="tipos_list">
                            <?php foreach ($tiposExistentes as $tipo): ?>
                                <option value="<?php echo htmlspecialchars($tipo); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small><i class="fas fa-info-circle"></i> Você pode digitar um novo tipo ou selecionar um já existente.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Status <span class="required">*</span></label>
                    <select name="status" id="status" required>
                        <option value="disponivel" <?php echo ($sala['status_sala'] == 'disponivel') ? 'selected' : ''; ?>>Disponível</option>
                        <option value="ocupada" <?php echo ($sala['status_sala'] == 'ocupada') ? 'selected' : ''; ?>>Ocupada</option>
                        <option value="inativa" <?php echo ($sala['status_sala'] == 'inativa') ? 'selected' : ''; ?>>Inativa</option>
                        <?php if ($sala['status_sala'] == 'manutencao'): ?>
                            <option value="manutencao" selected>Em Manutenção</option>
                        <?php endif; ?>
                    </select>
                    <?php if ($sala['status_sala'] !== 'inativa' && $sala['status_sala'] !== 'manutencao' && $totalAulasAfetadas > 0): ?>
                        <small style="color: #856404; display: block; margin-top: 6px;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Ao selecionar <strong>"Inativa"</strong>, <?php echo $totalAulasAfetadas; ?> aulas serão marcadas como <strong>"Aguardando Remarcação"</strong>.
                        </small>
                    <?php endif; ?>
                    <small style="color: #6c757d; display: block; margin-top: 4px;">
                        <i class="fas fa-info-circle"></i> 
                        O status "Em Manutenção" é aplicado <strong>automaticamente</strong> durante o período da manutenção.
                    </small>
                </div>

                <div class="form-group">
                    <label for="recursos">Recursos da Sala</label>
                    <textarea name="recursos" id="recursos" rows="3" 
                              placeholder="Descreva os recursos disponíveis (ex: projetor, quadro branco, ar-condicionado, 30 cadeiras...)"><?php echo htmlspecialchars($sala['recursos_sala'] ?? ''); ?></textarea>
                    <small><i class="fas fa-info-circle"></i> Informe os recursos da sala em texto livre.</small>
                </div>

                <div class="form-group">
                    <label for="descricao">Descrição</label>
                    <textarea name="descricao" id="descricao" rows="4" 
                              placeholder="Digite alguma observação sobre a sala..."><?php echo htmlspecialchars($sala['descricao_sala'] ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Atualizar Sala</button>
                    <button type="reset" class="btn btn-outline"><i class="fas fa-undo"></i> Limpar</button>
                    <a href="listar_salas.php" class="btn btn-danger"><i class="fas fa-times"></i> Cancelar</a>
                </div>
            </form>

            <?php if ($sala['status_sala'] !== 'inativa'): ?>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f0f4fb; text-align: center;">
                    <button class="btn btn-warning" onclick="abrirModalManutencao()">
                        <i class="fas fa-tools"></i> Registrar Manutenção
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- MANUTENÇÕES DA SALA -->
        <?php if (!empty($manutencoes)): ?>
            <div class="manutencoes-section">
                <h3><i class="fas fa-history"></i> Histórico de Manutenções</h3>
                <?php foreach ($manutencoes as $m): 
                    $statusClass = '';
                    $statusLabel = '';
                    $isEmAndamento = false;
                    $isCancelavel = false;
                    
                    $hoje = date('Y-m-d');
                    $dataInicio = $m['data_inicio'];
                    $dataFim = $m['data_fim'];
                    
                    $isFutura = ($dataInicio > $hoje);
                    $isConcluida = ($m['status'] === 'concluida');
                    
                    $isCancelavel = ($m['status'] === 'em_andamento' && !$isConcluida);
                    
                    if ($m['status'] === 'agendada' || $isFutura) {
                        $statusClass = 'status-agendada';
                        $statusLabel = '📅 Agendada';
                    } elseif ($m['status'] === 'em_andamento') {
                        $statusClass = 'status-em_andamento';
                        $statusLabel = '🔧 EM ANDAMENTO';
                        $isEmAndamento = true;
                    } elseif ($m['status'] === 'concluida') {
                        $statusClass = 'status-concluida';
                        $statusLabel = '✅ Concluída';
                    }

                    $turnosExibicao = [];
                    $turnosArray = explode(',', $m['turno']);
                    foreach ($turnosArray as $t) {
                        $t = trim($t);
                        if (isset($turnosLabels[$t])) {
                            $turnosExibicao[] = $turnosLabels[$t];
                        }
                    }
                ?>
                    <div class="manutencao-item">
                        <div class="info">
                            <span class="periodo">
                                📅 <?php echo date('d/m/Y', strtotime($m['data_inicio'])); ?>
                                <?php if ($m['data_inicio'] != $m['data_fim']): ?>
                                    → <?php echo date('d/m/Y', strtotime($m['data_fim'])); ?>
                                <?php endif; ?>
                            </span>
                            <span class="detalhes">
                                <i class="fas fa-clock"></i> <?php echo implode(', ', $turnosExibicao); ?>
                                <?php if (!empty($m['motivo'])): ?>
                                    • <i class="fas fa-comment"></i> <?php echo htmlspecialchars($m['motivo']); ?>
                                <?php endif; ?>
                                <?php if ($isFutura): ?>
                                    • <span style="color: #1a73e8;"><i class="fas fa-clock"></i> Inicia em <?php echo date('d/m/Y', strtotime($dataInicio)); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="actions" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                            
                            <?php if ($isEmAndamento): ?>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('⚠️ Confirmar finalização da manutenção?\n\nA sala será liberada para novos agendamentos.');">
                                    <input type="hidden" name="acao_finalizar_manutencao" value="1">
                                    <input type="hidden" name="id_manutencao" value="<?php echo $m['id_manutencao']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check"></i> Finalizar Manutenção
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if ($isCancelavel): ?>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm(
                                        '⚠️ ATENÇÃO! Confirmar cancelamento da manutenção?\n\n' +
                                        '📅 Período: <?php echo date('d/m/Y', strtotime($m['data_inicio'])); ?> a <?php echo date('d/m/Y', strtotime($m['data_fim'])); ?>\n' +
                                        '📌 Motivo: <?php echo htmlspecialchars($m['motivo']); ?>\n\n' +
                                        '✅ As aulas que estavam em "Aguardando Remarcação" serão revertidas para "Agendada".\n\n' +
                                        'Deseja realmente cancelar esta manutenção?' +
                                      );">
                                    <input type="hidden" name="acao_cancelar_manutencao" value="1">
                                    <input type="hidden" name="id_manutencao" value="<?php echo $m['id_manutencao']; ?>">
                                    <button type="submit" class="btn btn-cancel btn-sm">
                                        <i class="fas fa-times"></i> Cancelar Manutenção
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- MODAL - REGISTRAR MANUTENÇÃO -->
        <div class="modal-overlay" id="modalManutencao" onclick="if(event.target===this) this.classList.remove('active')">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-tools" style="color: #ffc107;"></i> Registrar Manutenção</h2>
                    <button class="close-btn" onclick="document.getElementById('modalManutencao').classList.remove('active')">&times;</button>
                </div>

                <div class="aviso-manutencao">
                    <div class="icone">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="conteudo">
                        <div class="titulo">📌 Como funciona o registro de manutenção?</div>
                        <div class="descricao">
                            Ao registrar uma manutenção para esta sala, todas as aulas <strong>agendadas</strong> ou <strong>remarcadas</strong> que caírem no período selecionado serão automaticamente marcadas com o status:
                        </div>
                        <ul class="lista">
                            <li><strong>⏳ Aguardando Remarcação</strong> - As aulas ficarão com este status até que um coordenador as remaneje manualmente para outra sala ou data.</li>
                            <li>📝 Será adicionada uma <strong>observação</strong> automática em cada aula, informando que a sala estava em manutenção.</li>
                            <li>🔒 A sala ficará com status <strong>"Em Manutenção"</strong> <strong>APENAS durante o período</strong> agendado.</li>
                            <li>📅 Se a manutenção for agendada para o futuro, a sala <strong>permanece disponível</strong> até a data de início.</li>
                            <li>🔄 Quando a manutenção for concluída, clique em <strong>"Finalizar Manutenção"</strong> para liberar a sala.</li>
                            <li>❌ Você pode <strong>cancelar</strong> a manutenção a qualquer momento antes de concluída.</li>
                        </ul>
                        <div style="margin-top: 8px; padding: 8px 12px; background: #fff3cd; border-radius: 6px; font-size: 13px; color: #856404;">
                            <i class="fas fa-info-circle"></i>
                            <strong>Importante:</strong> As aulas <strong>não serão remanejadas automaticamente</strong>. Você precisará remarcá-las manualmente após a manutenção.
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="formManutencao">
                    <input type="hidden" name="acao_manutencao" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="data_inicio_manut"><i class="fas fa-calendar-plus"></i> Data Início <span class="required">*</span></label>
                            <input type="date" name="data_inicio_manut" id="data_inicio_manut" required>
                        </div>
                        <div class="form-group">
                            <label for="data_fim_manut"><i class="fas fa-calendar-minus"></i> Data Fim <span class="required">*</span></label>
                            <input type="date" name="data_fim_manut" id="data_fim_manut" required>
                        </div>
                    </div>
                    <small><i class="fas fa-info-circle"></i> A manutenção será aplicada em todo o período selecionado.</small>

                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Turnos Afetados <span class="required">*</span></label>
                        <div class="checkbox-group" id="turnosContainer">
                            <?php foreach ($turnosLabels as $key => $label): ?>
                                <label class="turno-option" data-turno="<?php echo $key; ?>">
                                    <input type="checkbox" name="turnos_manut[]" value="<?php echo $key; ?>">
                                    <?php echo $label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small><i class="fas fa-info-circle"></i> Selecione todos os turnos afetados pela manutenção.</small>
                    </div>

                    <div class="form-group">
                        <label for="motivo_manut"><i class="fas fa-comment"></i> Motivo <span class="required">*</span></label>
                        <textarea name="motivo_manut" id="motivo_manut" rows="3" 
                                  placeholder="Descreva o motivo da manutenção..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-cogs"></i> Opções</label>
                        <div style="display: flex; align-items: center; gap: 12px; padding: 8px 0; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="incluir_sabado" id="incluir_sabado" value="1" checked>
                                <label for="incluir_sabado" style="font-weight: 400; cursor: pointer;">
                                    <i class="fas fa-calendar-week"></i> Incluir sábado
                                </label>
                            </div>
                        </div>
                        <small><i class="fas fa-info-circle"></i> "Incluir sábado" considera também os sábados como dias de manutenção.</small>
                    </div>

                    <div class="form-actions" style="border-top: none; margin-top: 10px;">
                        <button type="button" class="btn btn-outline" onclick="document.getElementById('modalManutencao').classList.remove('active')">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-warning" id="btnManutencao">
                            <i class="fas fa-save"></i> Registrar Manutenção
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <script>
        function abrirModalManutencao() {
            document.getElementById('modalManutencao').classList.add('active');
        }

        document.querySelectorAll('.turno-option input[type="checkbox"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const label = this.closest('.turno-option');
                if (this.checked) {
                    label.classList.add('selecionado');
                } else {
                    label.classList.remove('selecionado');
                }
            });
        });

        document.getElementById('data_fim_manut').addEventListener('change', function() {
            const inicio = document.getElementById('data_inicio_manut').value;
            const fim = this.value;
            if (inicio && fim && fim < inicio) {
                alert('⚠️ A data de fim não pode ser anterior à data de início.');
                this.value = '';
            }
        });

        document.getElementById('data_inicio_manut').addEventListener('change', function() {
            const fim = document.getElementById('data_fim_manut').value;
            const inicio = this.value;
            if (inicio && fim && fim < inicio) {
                alert('⚠️ A data de fim não pode ser anterior à data de início.');
                document.getElementById('data_fim_manut').value = '';
            }
        });

        document.getElementById('formManutencao').addEventListener('submit', function(e) {
            const dataInicio = document.getElementById('data_inicio_manut').value;
            const dataFim = document.getElementById('data_fim_manut').value;

            if (!dataInicio || !dataFim) {
                alert('⚠️ Selecione as datas de início e fim da manutenção.');
                e.preventDefault();
                return false;
            }

            if (dataFim < dataInicio) {
                alert('⚠️ A data de fim não pode ser anterior à data de início.');
                e.preventDefault();
                return false;
            }

            const turnos = document.querySelectorAll('input[name="turnos_manut[]"]:checked');
            if (turnos.length === 0) {
                alert('⚠️ Selecione pelo menos um turno para a manutenção.');
                e.preventDefault();
                return false;
            }

            const motivo = document.getElementById('motivo_manut').value.trim();
            if (!motivo) {
                alert('⚠️ Descreva o motivo da manutenção.');
                e.preventDefault();
                return false;
            }
            
            const btn = document.getElementById('btnManutencao');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando...';
        });

        document.getElementById('status').addEventListener('change', function() {
            const statusAtual = '<?php echo $sala['status_sala']; ?>';
            const novoStatus = this.value;
            const totalAulas = <?php echo $totalAulasAfetadas; ?>;

            if (novoStatus === 'inativa' && statusAtual !== 'inativa' && totalAulas > 0) {
                const confirmar = confirm(
                    '⚠️ ATENÇÃO!\n\n' +
                    'Esta sala possui ' + totalAulas + ' aula(s) futura(s) agendada(s).\n\n' +
                    'Ao marcar a sala como "INATIVA", TODAS essas aulas serão marcadas como "AGUARDANDO REMARCAÇÃO".\n\n' +
                    'Deseja continuar?'
                );
                if (!confirmar) {
                    this.value = statusAtual;
                    return;
                }
            }

            if (statusAtual === 'inativa' && novoStatus !== 'inativa') {
                const confirmar = confirm(
                    '📌 Reativar Sala\n\n' +
                    'Esta sala está atualmente INATIVA.\n\n' +
                    'Ao reativá-la, as aulas que estavam "AGUARDANDO REMARCAÇÃO" serão automaticamente reagendadas.\n\n' +
                    'Deseja continuar?'
                );
                if (!confirmar) {
                    this.value = statusAtual;
                    return;
                }
            }
        });

        document.querySelectorAll('.turno-option').forEach(function(label) {
            const checkbox = label.querySelector('input[type="checkbox"]');
            if (checkbox && checkbox.checked) {
                label.classList.add('selecionado');
            }
        });

        document.querySelector('.card-panel form').addEventListener('submit', function(e) {
            const btn = document.querySelector('.card-panel .btn-primary');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            }
        });
    </script>
</body>
</html>