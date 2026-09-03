<?php
// ==========================================================
// editar_aula.php - Edição de Aulas (MODIFICADO PARA MULTI-TENANT)
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
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem editar aulas.');
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
// BUSCAR DADOS DA AULA PARA EDIÇÃO (FILTRADOS POR CLIENTE)
// ============================================================
$id_aula = $_GET['id'] ?? 0;

if (empty($id_aula)) {
    setMessage('error', 'ID da aula não informado.');
    redirect('listar_aulas.php');
}

try {
    // Buscar dados da aula com informações relacionadas
    $sql = "SELECT 
                c.*, 
                cu.nome_curso,
                cu.id_unidade,
                cu.numero_curso,
                cu.dias_letivos,
                cu.data_inicio_curso,
                cu.data_fim_curso_calculada,
                cu.dias_semana,
                cu.status_curso,
                cu.id_cliente,
                f.nome_funcionario AS nome_professor,
                s.numero_sala,
                s.tipo_sala,
                s.capacidade_sala,
                s.recursos_sala,
                s.descricao_sala,
                u.nome_unidade,
                (SELECT COUNT(*) 
                 FROM cronograma c2 
                 WHERE c2.id_curso = c.id_curso 
                 AND c2.id_cliente = c.id_cliente
                 AND c2.status_aula != 'cancelada' 
                 AND (c2.data_aula < c.data_aula OR (c2.data_aula = c.data_aula AND c2.id_aula <= c.id_aula))) AS numero_aula_ordem
            FROM cronograma c
            LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
            LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
            LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
            LEFT JOIN unidades u ON cu.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
            WHERE c.id_aula = :id_aula AND c.id_cliente = :id_cliente";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id_aula' => $id_aula,
        ':id_cliente' => $id_cliente
    ]);
    $aula = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$aula) {
        setMessage('error', 'Aula não encontrada.');
        redirect('listar_aulas.php');
    }
    
    // Verificar permissão por unidade (para coordenador)
    if ($tipo_usuario === 'gerente' && $aula['id_unidade'] != $id_unidade_usuario) {
        setMessage('error', 'Você não tem permissão para editar esta aula.');
        redirect('listar_aulas.php');
    }
    
    // ============================================================
    // VERIFICAR STATUS DO CURSO - IMPEDIR EDIÇÃO DE CURSOS INATIVOS/CONCLUÍDOS
    // ============================================================
    if ($aula['status_curso'] === 'inativo' || $aula['status_curso'] === 'concluido') {
        setMessage('error', '❌ Não é possível editar aulas de cursos inativos ou concluídos.');
        redirect('listar_aulas.php');
    }
    
} catch (PDOException $e) {
    setMessage('error', '❌ Erro ao buscar agendamento: ' . $e->getMessage());
    redirect('listar_aulas.php');
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

try {
    if ($tipo_usuario === 'admin_cliente') {
        $sqlCursos = "SELECT id_curso, nome_curso, id_unidade 
                      FROM cursos 
                      WHERE id_cliente = :id_cliente
                      AND status_curso = 'ativo' 
                      ORDER BY nome_curso";
        $stmtCursos = $conn->prepare($sqlCursos);
        $stmtCursos->execute([':id_cliente' => $id_cliente]);
    } else {
        $sqlCursos = "SELECT id_curso, nome_curso, id_unidade 
                      FROM cursos 
                      WHERE id_unidade = :id_unidade 
                      AND id_cliente = :id_cliente
                      AND status_curso = 'ativo' 
                      ORDER BY nome_curso";
        $stmtCursos = $conn->prepare($sqlCursos);
        $stmtCursos->execute([
            ':id_unidade' => $id_unidade_usuario,
            ':id_cliente' => $id_cliente
        ]);
    }
    $cursos = $stmtCursos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cursos = [];
}

// ============================================================
// FUNÇÃO PARA RECALCULAR DATA DE FIM DO CURSO (MODIFICADA)
// ============================================================
function recalcularDataFimCurso($conn, $id_curso, $id_cliente) {
    try {
        // Buscar a maior data de aula que não esteja cancelada
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
            // Atualizar a data de fim do curso
            $sqlUpdate = "UPDATE cursos SET data_fim_curso_calculada = :data_fim 
                          WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':data_fim' => $resultado['ultima_data'],
                ':id_curso' => $id_curso,
                ':id_cliente' => $id_cliente
            ]);
            return ['success' => true, 'data_fim' => $resultado['ultima_data']];
        }
        return ['success' => true, 'message' => 'Nenhuma aula encontrada para o curso.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao recalcular data de fim: ' . $e->getMessage()];
    }
}

// ============================================================
// VERIFICAR DISPONIBILIDADE DE SALAS (AJAX) - MODIFICADO
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'verificar_salas') {
    header('Content-Type: application/json');
    
    $data_aula = $_GET['data_aula'] ?? '';
    $horario_inicio = $_GET['horario_inicio'] ?? '';
    $horario_fim = $_GET['horario_fim'] ?? '';
    $id_aula_atual = $_GET['id_aula'] ?? 0;
    
    if (empty($data_aula) || empty($horario_inicio) || empty($horario_fim)) {
        echo json_encode(['error' => 'Dados incompletos']);
        exit;
    }
    
    try {
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
                        AND cron.id_aula != :id_aula_atual
                        AND (
                            (cron.horario_inicio < :horario_fim AND cron.horario_fim > :horario_inicio)
                        )";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->execute([
                ':id_sala' => $sala['id_sala'],
                ':data_aula' => $data_aula,
                ':id_aula_atual' => $id_aula_atual,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':id_cliente' => $id_cliente
            ]);
            $conflito = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if ($conflito) {
                $disponivel = false;
                $conflitos[] = [
                    'horario' => substr($conflito['horario_inicio'] ?? '', 0, 5) . ' - ' . substr($conflito['horario_fim'] ?? '', 0, 5),
                    'curso' => $conflito['nome_curso'] ?? 'Não definido'
                ];
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
                'conflitos' => $conflitos
            ];
        }
        
        echo json_encode([
            'success' => true,
            'salas' => $salasComStatus,
            'salas_disponiveis' => $salasDisponiveis,
            'total_salas' => $totalSalas,
            'total_disponiveis' => $totalDisponiveis,
            'sala_atual' => $aula['id_sala'] ?? null
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao verificar salas: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PROCESSAR FORMULÁRIO DE EDIÇÃO (MODIFICADO)
// ============================================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_curso = $_POST['id_curso'] ?? '';
    $id_professor = $_POST['id_professor'] ?? null;
    $id_sala = $_POST['id_sala'] ?? '';
    $data_aula = $_POST['data_aula'] ?? '';
    $horario_inicio = $_POST['horario_inicio'] ?? '';
    $horario_fim = $_POST['horario_fim'] ?? '';
    $turno = $_POST['turno'] ?? '';
    $status_aula = $_POST['status_aula'] ?? '';
    $observacao = $_POST['observacao'] ?? '';
    
    // Campo para nova data (remarcação)
    $nova_data = $_POST['nova_data'] ?? '';
    
    // ============================================================
    // VERIFICAR STATUS DO CURSO ANTES DE PROCESSAR
    // ============================================================
    try {
        $sqlStatusCurso = "SELECT status_curso FROM cursos 
                           WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtStatus = $conn->prepare($sqlStatusCurso);
        $stmtStatus->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $statusCurso = $stmtStatus->fetchColumn();
        
        if ($statusCurso === 'inativo' || $statusCurso === 'concluido') {
            setMessage('error', '❌ Não é possível editar aulas de cursos inativos ou concluídos.');
            redirect('listar_aulas.php');
        }
    } catch (PDOException $e) {
        setMessage('error', '❌ Erro ao verificar status do curso.');
        redirect('listar_aulas.php');
    }
    
    // Validação básica
    $erros = [];
    if (empty($id_curso)) $erros[] = 'Curso é obrigatório.';
    if (empty($id_sala)) $erros[] = 'Sala é obrigatória.';
    if (empty($data_aula)) $erros[] = 'Data da aula é obrigatória.';
    if (empty($horario_inicio)) $erros[] = 'Horário de início é obrigatório.';
    if (empty($horario_fim)) $erros[] = 'Horário de fim é obrigatório.';
    if (empty($turno)) $erros[] = 'Turno é obrigatório.';
    if (empty($status_aula)) $erros[] = 'Status é obrigatório.';
    
    if (!empty($horario_inicio) && !empty($horario_fim) && $horario_inicio >= $horario_fim) {
        $erros[] = 'Horário de início deve ser anterior ao horário de fim.';
    }
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            $status_anterior = $aula['status_aula'];
            $data_original = $aula['data_aula'];
            $id_curso_atual = $aula['id_curso'];
            
            // ============================================================
            // VERIFICAR SE É REMARCAÇÃO (status = remarcada)
            // ============================================================
            if ($status_aula === 'remarcada') {
                // Validar nova data
                if (empty($nova_data)) {
                    throw new Exception('Selecione a nova data para remarcação.');
                }
                
                if (strtotime($nova_data) < strtotime(date('Y-m-d'))) {
                    throw new Exception('A nova data não pode ser no passado.');
                }
                
                // Verificar conflitos na nova data
                $sqlCheck = "SELECT COUNT(*) FROM cronograma 
                            WHERE id_sala = :id_sala 
                            AND data_aula = :nova_data 
                            AND id_cliente = :id_cliente
                            AND id_aula != :id_aula
                            AND ((horario_inicio < :horario_fim AND horario_fim > :horario_inicio))";
                $stmtCheck = $conn->prepare($sqlCheck);
                $stmtCheck->execute([
                    ':id_sala' => $id_sala,
                    ':nova_data' => $nova_data,
                    ':id_cliente' => $id_cliente,
                    ':id_aula' => $id_aula,
                    ':horario_inicio' => $horario_inicio,
                    ':horario_fim' => $horario_fim
                ]);
                $conflito = $stmtCheck->fetchColumn();
                
                if ($conflito > 0) {
                    throw new Exception('⚠️ Conflito de horário na nova data. Escolha outra data ou sala.');
                }
                
                // Atualizar a aula para a nova data
                $sqlUpdate = "UPDATE cronograma SET 
                                data_aula = :nova_data,
                                status_aula = 'agendada',
                                observacao = CONCAT(IFNULL(observacao, ''), ' | Aula remarcada de ', :data_original, ' para ', :nova_data_exibicao)
                              WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':nova_data' => $nova_data,
                    ':data_original' => date('d/m/Y', strtotime($data_original)),
                    ':nova_data_exibicao' => date('d/m/Y', strtotime($nova_data)),
                    ':id_aula' => $id_aula,
                    ':id_cliente' => $id_cliente
                ]);
                
                // ============================================================
                // RECALCULAR DATA DE FIM DO CURSO
                // ============================================================
                $resultadoRecalculo = recalcularDataFimCurso($conn, $id_curso_atual, $id_cliente);
                if (!$resultadoRecalculo['success']) {
                    throw new Exception($resultadoRecalculo['message']);
                }
                
                $dataFimAtualizada = $resultadoRecalculo['data_fim'] ?? 'Não definida';
                $mensagem_adicional = ' Data de fim do curso atualizada para ' . date('d/m/Y', strtotime($dataFimAtualizada));
                
                $conn->commit();
                setMessage('success', "✅ Aula remarcada para " . date('d/m/Y', strtotime($nova_data)) . " com sucesso!" . $mensagem_adicional);
                redirect('listar_aulas.php');
            }
            
            // ============================================================
            // CASO: CANCELADA
            // ============================================================
            if ($status_aula === 'cancelada') {
                // Marcar a aula como cancelada
                $sqlUpdate = "UPDATE cronograma SET 
                                status_aula = 'cancelada',
                                observacao = CONCAT(IFNULL(observacao, ''), ' | Aula cancelada em ', NOW())
                              WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':id_aula' => $id_aula,
                    ':id_cliente' => $id_cliente
                ]);
                
                // ============================================================
                // RECALCULAR DATA DE FIM DO CURSO
                // ============================================================
                $resultadoRecalculo = recalcularDataFimCurso($conn, $id_curso_atual, $id_cliente);
                if (!$resultadoRecalculo['success']) {
                    throw new Exception($resultadoRecalculo['message']);
                }
                
                $conn->commit();
                setMessage('success', "✅ Aula cancelada com sucesso!");
                redirect('listar_aulas.php');
            }
            
            // ============================================================
            // CASO: OUTROS STATUS (agendada, realizada, aguardando_remarcacao)
            // ============================================================
            // Verificar conflitos
            $sqlCheck = "SELECT COUNT(*) FROM cronograma 
                        WHERE id_sala = :id_sala 
                        AND data_aula = :data_aula 
                        AND id_cliente = :id_cliente
                        AND id_aula != :id_aula
                        AND ((horario_inicio < :horario_fim AND horario_fim > :horario_inicio))";
            $stmtCheck = $conn->prepare($sqlCheck);
            $stmtCheck->execute([
                ':id_sala' => $id_sala,
                ':data_aula' => $data_aula,
                ':id_cliente' => $id_cliente,
                ':id_aula' => $id_aula,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim
            ]);
            $conflito = $stmtCheck->fetchColumn();
            
            if ($conflito > 0) {
                throw new Exception('⚠️ Já existe uma aula agendada nesta sala neste horário.');
            }
            
            // Atualizar a aula
            $sqlUpdate = "UPDATE cronograma SET 
                            id_professor = :id_professor,
                            id_sala = :id_sala,
                            horario_inicio = :horario_inicio,
                            horario_fim = :horario_fim,
                            status_aula = :status_aula,
                            observacao = CONCAT(IFNULL(observacao, ''), ' | ', :observacao)
                        WHERE id_aula = :id_aula AND id_cliente = :id_cliente";
            
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':id_professor' => !empty($id_professor) ? $id_professor : null,
                ':id_sala' => $id_sala,
                ':horario_inicio' => $horario_inicio,
                ':horario_fim' => $horario_fim,
                ':status_aula' => $status_aula,
                ':observacao' => $observacao,
                ':id_aula' => $id_aula,
                ':id_cliente' => $id_cliente
            ]);
            
            // Se for alterado para 'aguardando_remarcacao', liberar a sala
            if ($status_aula === 'aguardando_remarcacao') {
                $mensagem_adicional = ' A sala foi liberada para este horário.';
            }
            
            // ============================================================
            // RECALCULAR DATA DE FIM DO CURSO
            // ============================================================
            $resultadoRecalculo = recalcularDataFimCurso($conn, $id_curso_atual, $id_cliente);
            if (!$resultadoRecalculo['success']) {
                throw new Exception($resultadoRecalculo['message']);
            }
            
            $conn->commit();
            setMessage('success', "✅ Aula atualizada com sucesso!" . ($mensagem_adicional ?? ''));
            redirect('listar_aulas.php');
            
        } catch (Exception $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $mensagem_erro = '❌ ' . $e->getMessage();
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $mensagem_erro = '❌ Erro ao atualizar aula: ' . $e->getMessage();
        }
    } else {
        $mensagem_erro = '⚠️ ' . implode(' ', $erros);
    }
}

// Mensagens da sessão
$message = getMessage();

$titulo = 'Editar Aula - Gerenciamento de Ambientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <style>
        /* MANTIDO O MESMO CSS DO SEU ARQUIVO ORIGINAL */
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        .page-title { font-size: 24px; font-weight: 700; color: #0e1a2b; }
        .page-title i { color: #1a73e8; margin-right: 10px; }
        .page-subtitle { font-size: 14px; color: #7a8aa0; margin-top: 4px; }
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
        .btn-secondary { background: #e2e9f3; color: #1a2639; border: 1px solid #d8e0ec; }
        .btn-secondary:hover { background: #d0dbe8; }
        .btn-primary { background: #1a73e8; color: #ffffff; border: none; box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35); }
        .btn-primary:hover { background: #1557b0; transform: scale(1.02); }
        .btn-danger { background: #dc3545; color: #ffffff; border: none; }
        .btn-danger:hover { background: #c82333; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #5a6a7e; margin-bottom: 4px; }
        .form-group label i { margin-right: 6px; color: #1a73e8; }
        .form-group label .required { color: #dc3545; margin-left: 2px; }
        .form-group label .optional { color: #7a8aa0; font-weight: 400; font-size: 12px; margin-left: 4px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            background: #fafcff;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
            outline: none;
        }
        .form-group input:disabled {
            background: #f0f4fb;
            color: #6c7a8e;
            cursor: not-allowed;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end; flex-wrap: wrap; }

        .curso-periodo {
            background: #f8faff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid #1a73e8;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        .curso-periodo .item { text-align: center; }
        .curso-periodo .item .label { font-size: 11px; color: #7a8aa0; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        .curso-periodo .item .value { font-size: 16px; font-weight: 700; color: #0e1a2b; margin-top: 2px; }
        .curso-periodo .item .value .badge-dia {
            display: inline-block;
            background: #e3f2fd;
            color: #0d47a1;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
        .alert-danger { background: #ffe9e9; color: #b33a3a; border: 1px solid #ffd6d6; }
        .alert-success { background: #e6f7e9; color: #1e8546; border: 1px solid #c8f0cf; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .alert i { font-size: 18px; }
        .info-box {
            background: #f8faff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-left: 3px solid #1a73e8;
        }
        .info-box p { font-size: 13px; color: #5a6a7e; margin: 4px 0; }
        .info-box strong { color: #0e1a2b; }

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

        .salas-container {
            margin-top: 10px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            padding: 10px;
            background: #fafcff;
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
        .sala-item:hover { border-color: #1a73e8; box-shadow: 0 2px 8px rgba(26, 115, 232, 0.1); }
        .sala-item.disponivel { border-left: 4px solid #28a745; }
        .sala-item.indisponivel { border-left: 4px solid #dc3545; opacity: 0.7; cursor: not-allowed; }
        .sala-item.selecionada { background: #e3f2fd; border-color: #1a73e8; border-left: 4px solid #1a73e8; }
        .sala-item .info { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .sala-item .info .numero { font-weight: 600; font-size: 14px; color: #0e1a2b; }
        .sala-item .info .detalhes { font-size: 12px; color: #7a8aa0; }
        .sala-item .info .detalhes i { margin-right: 4px; }
        .sala-item .status-badge { font-size: 12px; font-weight: 500; padding: 4px 12px; border-radius: 20px; }
        .sala-item .status-badge.disponivel { background: #e6f7e9; color: #1e8546; }
        .sala-item .status-badge.indisponivel { background: #ffe9e9; color: #b33a3a; }
        .sala-item .status-badge.selecionada { background: #1a73e8; color: #ffffff; }

        .loading-salas {
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

        .status-message {
            display: none;
            padding: 10px 14px;
            border-radius: 8px;
            margin-top: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        .status-message.active { display: block; }
        .status-message.agendada { background: #e3f2fd; color: #0d47a1; border-left: 4px solid #1a73e8; }
        .status-message.realizada { background: #e6f7e9; color: #1e8546; border-left: 4px solid #28a745; }
        .status-message.cancelada { background: #ffe9e9; color: #b33a3a; border-left: 4px solid #dc3545; }
        .status-message.remarcada { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .status-message.aguardando_remarcacao { background: #f0f7ff; color: #0d47a1; border-left: 4px solid #17a2b8; }

        .campo-remarcacao {
            display: none;
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #1a73e8;
            margin-top: 10px;
        }
        .campo-remarcacao.active { display: block; }
        .campo-remarcacao label small { font-weight: 400; color: #5a6a7e; }
        .campo-remarcacao input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9f3;
            border-radius: 8px;
            font-size: 14px;
            background: #fafcff;
        }
        .campo-remarcacao small { color: #7a8aa0; font-size: 12px; display: block; margin-top: 4px; }

        .curso-status-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .curso-status-warning i { font-size: 20px; }

        .badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #e6f7e9; color: #1e8546; }
        .badge-danger { background: #ffe9e9; color: #b33a3a; }
        .badge-info { background: #e3f2fd; color: #0d47a1; }

        @media (max-width: 640px) {
            .main { padding: 16px; }
            .card-panel { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .curso-periodo { grid-template-columns: 1fr; gap: 6px; }
            .curso-periodo .item { text-align: left; }
            .sala-item { flex-direction: column; align-items: flex-start; gap: 6px; }
        }
        @media (max-width: 820px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">
        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-edit"></i> Editar Aula</h1>
                <p class="page-subtitle">Atualize as informações da aula selecionada</p>
            </div>
            <a href="listar_aulas.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </header>

        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($mensagem_erro); ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message['tipo']; ?>">
                <i class="fas fa-<?php echo $message['tipo'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message['mensagem']); ?>
            </div>
        <?php endif; ?>

        <!-- AVISO SOBRE STATUS DO CURSO -->
        <?php if ($aula['status_curso'] === 'inativo'): ?>
            <div class="curso-status-warning">
                <i class="fas fa-ban" style="color: #dc3545;"></i>
                <div>
                    <strong>Curso Inativo!</strong> 
                    Este curso está <strong>inativo</strong>. Não é possível editar aulas de cursos inativos.
                    <br><small>Para editar esta aula, reative o curso primeiro.</small>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($aula['status_curso'] === 'concluido'): ?>
            <div class="curso-status-warning">
                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                <div>
                    <strong>Curso Concluído!</strong> 
                    Este curso está <strong>concluído</strong>. Não é possível editar aulas de cursos concluídos.
                    <br><small>Para editar esta aula, reative o curso primeiro.</small>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-panel">
            <!-- INFORMAÇÕES DO CURSO -->
            <?php 
                $dataInicioCurso = !empty($aula['data_inicio_curso']) ? date('d/m/Y', strtotime($aula['data_inicio_curso'])) : 'Não definida';
                $dataFimCurso = !empty($aula['data_fim_curso_calculada']) ? date('d/m/Y', strtotime($aula['data_fim_curso_calculada'])) : 'Não definida';
                
                $numeroAula = $aula['numero_aula_ordem'] ?? '-';
                $dataInicioCursoComparacao = $aula['data_inicio_curso'] ?? null;
                $cursoComecou = !empty($dataInicioCursoComparacao) && strtotime($dataInicioCursoComparacao) <= time();
                
                if ($numeroAula !== '-' && $cursoComecou) {
                    $numeroAulaExibicao = $numeroAula;
                } elseif ($numeroAula !== '-' && !$cursoComecou) {
                    $numeroAulaExibicao = 0;
                } else {
                    $numeroAulaExibicao = '-';
                }
                
                $totalDiasLetivos = $aula['dias_letivos'] ?? '-';
            ?>
            <div class="curso-periodo">
                <div class="item">
                    <div class="label"><i class="fas fa-calendar-plus"></i> Início do Curso</div>
                    <div class="value"><?php echo $dataInicioCurso; ?></div>
                </div>
                <div class="item">
                    <div class="label"><i class="fas fa-calendar-check"></i> Fim do Curso</div>
                    <div class="value"><?php echo $dataFimCurso; ?></div>
                </div>
                <div class="item">
                    <div class="label"><i class="fas fa-sort-numeric-up"></i> Dia Letivo</div>
                    <div class="value">
                        <?php if ($numeroAulaExibicao !== '-'): ?>
                            <span class="badge-dia">
                                <?php echo $numeroAulaExibicao; ?>º / <?php echo $totalDiasLetivos; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Info box -->
            <div class="info-box">
                <p><i class="fas fa-info-circle"></i> <strong>Unidade:</strong> <?php echo htmlspecialchars($aula['nome_unidade'] ?? 'Não definida'); ?></p>
                <p><i class="fas fa-book"></i> <strong>Curso:</strong> <?php echo htmlspecialchars($aula['nome_curso'] ?? 'Não definido'); ?> (<?php echo htmlspecialchars($aula['numero_curso'] ?? 'N/A'); ?>)</p>
                <p><i class="fas fa-user-tie"></i> <strong>Professor atual:</strong> <?php echo htmlspecialchars($aula['nome_professor'] ?? 'Não definido'); ?></p>
                <p><i class="fas fa-door-open"></i> <strong>Sala atual:</strong> <?php echo htmlspecialchars($aula['numero_sala'] ?? 'Não definida'); ?></p>
                <p><i class="fas fa-calendar-day"></i> <strong>Data atual:</strong> <?php echo date('d/m/Y', strtotime($aula['data_aula'])); ?></p>
                <p><i class="fas fa-clock"></i> <strong>Turno:</strong> <?php echo ucfirst($aula['turno']); ?></p>
                <p><i class="fas fa-circle"></i> <strong>Status do Curso:</strong> 
                    <?php 
                        $statusClass = '';
                        $statusText = $aula['status_curso'];
                        if ($aula['status_curso'] === 'ativo') {
                            $statusClass = 'badge-success';
                            $statusText = '✅ Ativo';
                        } elseif ($aula['status_curso'] === 'inativo') {
                            $statusClass = 'badge-danger';
                            $statusText = '❌ Inativo';
                        } elseif ($aula['status_curso'] === 'concluido') {
                            $statusClass = 'badge-info';
                            $statusText = '✅ Concluído';
                        }
                    ?>
                    <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                </p>
            </div>

            <form method="POST" action="" id="formEdicao">
                <input type="hidden" name="id_curso" value="<?php echo $aula['id_curso']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="id_curso"><i class="fas fa-book"></i> Curso *</label>
                        <select name="id_curso_display" id="id_curso" disabled>
                            <option value="<?php echo $aula['id_curso']; ?>" selected>
                                <?php echo htmlspecialchars($aula['numero_curso'] . ' - ' . $aula['nome_curso']); ?>
                            </option>
                        </select>
                        <small style="color: #6c757d; font-size: 12px;">
                            <i class="fas fa-lock"></i> O curso não pode ser alterado
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="id_professor_text"><i class="fas fa-user-tie"></i> Professor <span class="optional">(opcional)</span></label>
                        <input type="text" name="id_professor_text" id="id_professor_text" 
                               list="professores_list" 
                               placeholder="Digite o nome do professor ou selecione"
                               value="<?php echo htmlspecialchars($aula['nome_professor'] ?? ''); ?>"
                               autocomplete="off">
                        <input type="hidden" name="id_professor" id="id_professor" value="<?php echo $aula['id_professor']; ?>">
                        <datalist id="professores_list">
                            <?php foreach ($professores as $professor): ?>
                                <option value="<?php echo htmlspecialchars($professor['nome_funcionario']); ?>" 
                                        data-id="<?php echo $professor['id_funcionario']; ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small style="color: #7a8aa0; font-size: 12px;">
                            <i class="fas fa-info-circle"></i> Digite o nome do professor ou selecione da lista
                        </small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="id_sala"><i class="fas fa-door-open"></i> Sala *</label>
                        <select name="id_sala" id="id_sala" required>
                            <option value="">Selecione uma sala</option>
                            <?php foreach ($todasSalas as $sala): ?>
                                <option value="<?php echo $sala['id_sala']; ?>" 
                                    <?php echo ($sala['id_sala'] == $aula['id_sala']) ? 'selected' : ''; ?>
                                    data-tipo="<?php echo htmlspecialchars($sala['tipo_sala'] ?? ''); ?>"
                                    data-capacidade="<?php echo $sala['capacidade_sala'] ?? ''; ?>"
                                    data-recursos="<?php echo htmlspecialchars($sala['recursos_sala'] ?? ''); ?>">
                                    Sala <?php echo htmlspecialchars($sala['numero_sala']); ?>
                                    <?php if (!empty($sala['tipo_sala'])): ?>
                                        - <?php echo htmlspecialchars($sala['tipo_sala']); ?>
                                    <?php endif; ?>
                                    (<?php echo $sala['capacidade_sala']; ?> pessoas)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div id="salasDisponiveis" style="margin-top: 10px;">
                            <div class="loading-salas" id="loadingSalas">
                                <i class="fas fa-spinner"></i> Verificando disponibilidade das salas...
                            </div>
                            <div id="listaSalas" style="display: none;"></div>
                        </div>
                        
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> Clique em uma sala <strong>disponível</strong> na lista para selecioná-la
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="data_aula"><i class="fas fa-calendar-day"></i> Data da Aula *</label>
                        <input type="date" name="data_aula_display" id="data_aula_display" 
                               value="<?php echo htmlspecialchars($aula['data_aula']); ?>" 
                               disabled>
                        <input type="hidden" name="data_aula" value="<?php echo htmlspecialchars($aula['data_aula']); ?>">
                        <small style="color: #6c757d; font-size: 12px;">
                            <i class="fas fa-lock"></i> Para alterar a data, use a opção "Remarcada"
                        </small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="horario_inicio"><i class="fas fa-clock"></i> Horário Início *</label>
                        <input type="time" name="horario_inicio" id="horario_inicio" 
                               value="<?php echo htmlspecialchars($aula['horario_inicio']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="horario_fim"><i class="fas fa-clock"></i> Horário Fim *</label>
                        <input type="time" name="horario_fim" id="horario_fim" 
                               value="<?php echo htmlspecialchars($aula['horario_fim']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="turno"><i class="fas fa-sun"></i> Turno *</label>
                        <select name="turno_display" id="turno_display" disabled>
                            <option value="manha" <?php echo ($aula['turno'] == 'manha') ? 'selected' : ''; ?>>☀️ Manhã</option>
                            <option value="tarde" <?php echo ($aula['turno'] == 'tarde') ? 'selected' : ''; ?>>☀️ Tarde</option>
                            <option value="noite" <?php echo ($aula['turno'] == 'noite') ? 'selected' : ''; ?>>🌙 Noite</option>
                        </select>
                        <input type="hidden" name="turno" value="<?php echo $aula['turno']; ?>">
                        <small style="color: #6c757d; font-size: 12px;">
                            <i class="fas fa-lock"></i> O turno não pode ser alterado
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="status_aula"><i class="fas fa-circle"></i> Status *</label>
                        <select name="status_aula" id="status_aula" required>
                            <option value="agendada" <?php echo ($aula['status_aula'] == 'agendada') ? 'selected' : ''; ?>>📅 Agendada</option>
                            <option value="realizada" <?php echo ($aula['status_aula'] == 'realizada') ? 'selected' : ''; ?>>✅ Realizada</option>
                            <option value="cancelada" <?php echo ($aula['status_aula'] == 'cancelada') ? 'selected' : ''; ?>>❌ Cancelada</option>
                            <option value="remarcada" <?php echo ($aula['status_aula'] == 'remarcada') ? 'selected' : ''; ?>>🔄 Remarcada</option>
                            <option value="aguardando_remarcacao" <?php echo ($aula['status_aula'] == 'aguardando_remarcacao') ? 'selected' : ''; ?>>⏳ Aguardando Remarcação</option>
                        </select>
                        
                        <div id="statusMessage" class="status-message"></div>
                    </div>
                </div>

                <!-- CAMPO PARA REMARCAÇÃO -->
                <div class="campo-remarcacao" id="campoRemarcacao">
                    <div class="form-group">
                        <label for="nova_data">
                            <i class="fas fa-calendar-plus" style="color: #1a73e8;"></i> 
                            Nova Data para Remarcação *
                            <small style="font-weight: 400; color: #5a6a7e;">(selecione a nova data para a aula)</small>
                        </label>
                        <input type="date" name="nova_data" id="nova_data" 
                               min="<?php echo date('Y-m-d'); ?>"
                               value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                        <small style="color: #7a8aa0; font-size: 12px; display: block; margin-top: 4px;">
                            <i class="fas fa-info-circle"></i> A nova data deve ser futura e não pode ter conflitos de horário
                        </small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="observacao"><i class="fas fa-comment"></i> Observação</label>
                    <textarea name="observacao" id="observacao" 
                              placeholder="Digite observações sobre esta aula (opcional)"><?php echo htmlspecialchars($aula['observacao'] ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="listar_aulas.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary" id="btnSalvar">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </form>
        </div>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>
    </main>

    <script>
        // MANTIDO O MESMO JAVASCRIPT DO SEU ARQUIVO ORIGINAL
        $(document).ready(function() {
            // ============================================================
            // 1. PROFESSOR - input + datalist
            // ============================================================
            const professorInput = document.getElementById('id_professor_text');
            const professorHidden = document.getElementById('id_professor');
            
            const professorMap = {};
            document.querySelectorAll('#professores_list option').forEach(function(opt) {
                professorMap[opt.value] = opt.dataset.id;
            });
            
            professorInput.addEventListener('input', function() {
                const nome = this.value.trim();
                if (nome && professorMap[nome]) {
                    professorHidden.value = professorMap[nome];
                } else if (!nome) {
                    professorHidden.value = '';
                }
            });
            
            professorInput.addEventListener('blur', function() {
                const nome = this.value.trim();
                if (nome && !professorMap[nome]) {
                    this.value = '';
                    professorHidden.value = '';
                }
            });

            // ============================================================
            // 2. SALAS DISPONÍVEIS - AJAX
            // ============================================================
            let timeoutVerificarSalas = null;
            
            function verificarSalasDisponiveis() {
                const dataAula = document.getElementById('data_aula_display').value;
                const horarioInicio = document.getElementById('horario_inicio').value;
                const horarioFim = document.getElementById('horario_fim').value;
                const idAula = <?php echo $id_aula; ?>;
                
                if (!dataAula || !horarioInicio || !horarioFim) {
                    document.getElementById('listaSalas').style.display = 'none';
                    document.getElementById('loadingSalas').style.display = 'none';
                    return;
                }
                
                document.getElementById('loadingSalas').style.display = 'block';
                document.getElementById('listaSalas').style.display = 'none';
                
                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    data: {
                        ajax: 'verificar_salas',
                        data_aula: dataAula,
                        horario_inicio: horarioInicio,
                        horario_fim: horarioFim,
                        id_aula: idAula
                    },
                    dataType: 'json',
                    success: function(response) {
                        document.getElementById('loadingSalas').style.display = 'none';
                        
                        if (response.error) {
                            console.error('Erro:', response.error);
                            return;
                        }
                        
                        if (response.success) {
                            const salas = response.salas;
                            const totalDisponiveis = response.total_disponiveis;
                            const salaAtual = response.sala_atual;
                            
                            let html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">';
                            html += '<strong style="font-size: 13px; color: #0e1a2b;"><i class="fas fa-building" style="color: #1a73e8;"></i> Salas Disponíveis</strong>';
                            html += '<span style="font-size: 13px; color: #5a6a7e;">✅ ' + totalDisponiveis + ' disponíveis | ❌ ' + (salas.length - totalDisponiveis) + ' ocupadas</span>';
                            html += '</div>';
                            
                            html += '<div class="salas-container">';
                            
                            if (salas.length === 0) {
                                html += '<div style="text-align: center; padding: 20px; color: #7a8aa0;">';
                                html += '<i class="fas fa-info-circle" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>';
                                html += 'Nenhuma sala cadastrada nesta unidade.';
                                html += '</div>';
                            } else {
                                salas.forEach(function(item) {
                                    const sala = item.sala;
                                    const disponivel = item.disponivel;
                                    const conflitos = item.conflitos || [];
                                    const isSelecionada = (sala.id_sala == salaAtual);
                                    
                                    const statusClass = disponivel ? 'disponivel' : 'indisponivel';
                                    const statusText = disponivel ? '✅ Disponível' : '❌ Ocupada';
                                    const selecionadaClass = isSelecionada ? 'selecionada' : '';
                                    
                                    let recursosText = '';
                                    if (sala.recursos_sala && typeof sala.recursos_sala === 'object') {
                                        const recursosList = Object.keys(sala.recursos_sala).filter(key => {
                                            const value = sala.recursos_sala[key];
                                            return value === true || value === 1 || value === 'true' || value === '1';
                                        });
                                        if (recursosList.length > 0) {
                                            recursosText = recursosList.slice(0, 2).join(', ');
                                            if (recursosList.length > 2) recursosText += '...';
                                        }
                                    }
                                    
                                    html += '<div class="sala-item ' + statusClass + ' ' + selecionadaClass + '" data-id="' + sala.id_sala + '" data-disponivel="' + disponivel + '" onclick="selecionarSala(' + sala.id_sala + ')">';
                                    html += '    <div class="info">';
                                    html += '        <div class="numero">';
                                    html += '            <i class="fas fa-door-open"></i> Sala ' + sala.numero_sala;
                                    html += '            <span style="font-size: 12px; color: #7a8aa0; margin-left: 6px;">(' + sala.tipo_sala.replace(/_/g, ' ') + ')</span>';
                                    if (isSelecionada) {
                                        html += '            <span style="font-size: 11px; color: #1a73e8; margin-left: 8px; background: #e3f2fd; padding: 2px 10px; border-radius: 12px;">Atual</span>';
                                    }
                                    html += '        </div>';
                                    html += '        <div class="detalhes">';
                                    html += '            <i class="fas fa-users"></i> ' + sala.capacidade_sala + ' pessoas';
                                    if (recursosText) {
                                        html += ' | <i class="fas fa-tools"></i> ' + recursosText;
                                    }
                                    if (sala.descricao_sala) {
                                        html += ' | ' + sala.descricao_sala;
                                    }
                                    if (!disponivel && conflitos.length > 0) {
                                        html += ' | <span style="color: #dc3545;"><i class="fas fa-exclamation-circle"></i> Ocupado: ' + conflitos[0].horario + ' - ' + conflitos[0].curso + '</span>';
                                    }
                                    html += '        </div>';
                                    html += '    </div>';
                                    html += '    <div class="status-badge ' + statusClass + '">' + statusText + '</div>';
                                    html += '</div>';
                                });
                            }
                            
                            html += '</div>';
                            
                            document.getElementById('listaSalas').innerHTML = html;
                            document.getElementById('listaSalas').style.display = 'block';
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Erro na requisição AJAX:', error);
                        document.getElementById('loadingSalas').style.display = 'none';
                    }
                });
            }
            
            window.selecionarSala = function(idSala) {
                document.getElementById('id_sala').value = idSala;
                document.querySelectorAll('.sala-item').forEach(function(item) {
                    item.classList.remove('selecionada');
                    if (item.dataset.id == idSala) {
                        item.classList.add('selecionada');
                    }
                });
            };
            
            document.getElementById('data_aula_display').addEventListener('change', function() {
                clearTimeout(timeoutVerificarSalas);
                timeoutVerificarSalas = setTimeout(verificarSalasDisponiveis, 500);
            });
            
            document.getElementById('horario_inicio').addEventListener('change', function() {
                clearTimeout(timeoutVerificarSalas);
                timeoutVerificarSalas = setTimeout(verificarSalasDisponiveis, 500);
            });
            
            document.getElementById('horario_fim').addEventListener('change', function() {
                clearTimeout(timeoutVerificarSalas);
                timeoutVerificarSalas = setTimeout(verificarSalasDisponiveis, 500);
            });

            // ============================================================
            // 3. MENSAGENS EXPLICATIVAS POR STATUS
            // ============================================================
            const statusSelect = document.getElementById('status_aula');
            const statusMessage = document.getElementById('statusMessage');
            const campoRemarcacao = document.getElementById('campoRemarcacao');
            const novaData = document.getElementById('nova_data');
            
            const statusMessages = {
                'agendada': {
                    class: 'agendada',
                    icon: '📅',
                    title: 'Agendada',
                    message: 'A aula está agendada para a data e horário definidos. O sistema manterá a aula no cronograma normalmente.'
                },
                'realizada': {
                    class: 'realizada',
                    icon: '✅',
                    title: 'Realizada',
                    message: 'A aula foi ministrada conforme o planejado. O sistema marcará a aula como concluída e atualizará o percentual de conclusão do curso.'
                },
                'cancelada': {
                    class: 'cancelada',
                    icon: '❌',
                    title: 'Cancelada',
                    message: 'A aula será cancelada e a sala será liberada para este horário. Nenhuma outra aula será afetada.'
                },
                'remarcada': {
                    class: 'remarcada',
                    icon: '🔄',
                    title: 'Remarcada',
                    message: 'A aula será remarcada para uma nova data. Selecione a nova data abaixo. A data de fim do curso será atualizada automaticamente.'
                },
                'aguardando_remarcacao': {
                    class: 'aguardando_remarcacao',
                    icon: '⏳',
                    title: 'Aguardando Remarcação',
                    message: 'A aula está aguardando uma nova data para ser remarcada. A sala foi liberada para este horário.'
                }
            };
            
            function mostrarMensagemStatus(status) {
                const info = statusMessages[status];
                if (info) {
                    statusMessage.className = 'status-message active ' + info.class;
                    statusMessage.innerHTML = '<strong>' + info.icon + ' ' + info.title + ':</strong> ' + info.message;
                } else {
                    statusMessage.className = 'status-message';
                    statusMessage.innerHTML = '';
                }
            }
            
            function toggleCampoRemarcacao() {
                if (statusSelect.value === 'remarcada') {
                    campoRemarcacao.classList.add('active');
                    novaData.required = true;
                    
                    if (!novaData.value) {
                        const dataObj = new Date();
                        dataObj.setDate(dataObj.getDate() + 7);
                        while (dataObj.getDay() === 0 || dataObj.getDay() === 6) {
                            dataObj.setDate(dataObj.getDate() + 1);
                        }
                        novaData.value = dataObj.toISOString().split('T')[0];
                    }
                } else {
                    campoRemarcacao.classList.remove('active');
                    novaData.required = false;
                    novaData.value = '';
                }
            }
            
            mostrarMensagemStatus(statusSelect.value);
            toggleCampoRemarcacao();
            
            statusSelect.addEventListener('change', function() {
                mostrarMensagemStatus(this.value);
                toggleCampoRemarcacao();
            });

            // ============================================================
            // 4. VALIDAR HORÁRIOS
            // ============================================================
            document.getElementById('horario_fim').addEventListener('change', function() {
                const inicio = document.getElementById('horario_inicio').value;
                const fim = this.value;
                if (inicio && fim && inicio >= fim) {
                    alert('⚠️ O horário de fim deve ser posterior ao horário de início.');
                    this.value = '';
                }
            });

            document.getElementById('horario_inicio').addEventListener('change', function() {
                const fim = document.getElementById('horario_fim').value;
                const inicio = this.value;
                if (inicio && fim && inicio >= fim) {
                    alert('⚠️ O horário de início deve ser anterior ao horário de fim.');
                    document.getElementById('horario_fim').value = '';
                }
            });

            // ============================================================
            // 5. VALIDAR NOVA DATA (remarcação)
            // ============================================================
            document.getElementById('nova_data').addEventListener('change', function() {
                const data = this.value;
                if (data) {
                    const hoje = new Date();
                    hoje.setHours(0, 0, 0, 0);
                    const dataObj = new Date(data + 'T00:00:00');
                    
                    if (dataObj < hoje) {
                        alert('⚠️ A nova data não pode ser no passado.');
                        this.value = '';
                    }
                }
            });

            // ============================================================
            // 6. VALIDAR FORMULÁRIO ANTES DE ENVIAR
            // ============================================================
            document.getElementById('formEdicao').addEventListener('submit', function(e) {
                const status = document.getElementById('status_aula').value;
                const novaData = document.getElementById('nova_data').value;
                
                if (status === 'remarcada' && !novaData) {
                    alert('⚠️ Selecione a nova data para remarcação.');
                    e.preventDefault();
                    return false;
                }
                
                const btn = document.getElementById('btnSalvar');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            });

            // ============================================================
            // 7. INICIALIZAR VERIFICAÇÃO DE SALAS
            // ============================================================
            setTimeout(verificarSalasDisponiveis, 1000);
        });
    </script>
</body>
</html>