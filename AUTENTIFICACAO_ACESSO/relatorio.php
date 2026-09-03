<?php
// ============================================================
// ARQUIVO: painel_coordenador.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Painel do Coordenador - Gestão de Aulas e Progresso
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// INICIAR SESSÃO
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// VERIFICAR PERMISSÃO DE ACESSO (NOVO SISTEMA)
// ============================================================
// Tipos permitidos: admin_cliente, gerente (coordenador)
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Verificar se tem permissão (admin_cliente ou gerente)
$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Você não tem permissão para acessar esta página.');
    redirect('dashboard.php');
}

// ============================================================
// VARIÁVEIS DE PERMISSÃO (NOVO SISTEMA)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();
$tipo_usuario = $_SESSION['tipo_usuario'] ?? '';
$nome_cliente = $_SESSION['nome_cliente'] ?? '';
$id_unidade = $_SESSION['usuario_unidade'] ?? 0; // Mantido para compatibilidade

// Se não tiver unidade definida, buscar a primeira unidade do cliente
if ($id_unidade == 0) {
    try {
        $stmt = $conn->prepare("SELECT id_unidade FROM unidades WHERE id_cliente = ? ORDER BY id_unidade LIMIT 1");
        $stmt->execute([$id_cliente]);
        $unidade = $stmt->fetch();
        if ($unidade) {
            $id_unidade = $unidade['id_unidade'];
            $_SESSION['usuario_unidade'] = $id_unidade;
        }
    } catch (PDOException $e) {
        $id_unidade = 0;
    }
}

$nome_unidade = '';

// Buscar nome da unidade do coordenador
try {
    $sql_unidade = "SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql_unidade);
    $stmt->execute([
        ':id' => $id_unidade,
        ':id_cliente' => $id_cliente
    ]);
    $nome_unidade = $stmt->fetchColumn() ?: 'Unidade não definida';
} catch (PDOException $e) {
    $nome_unidade = 'Unidade não definida';
}

// ============================================================
// PROCESSAR AÇÕES (POST)
// ============================================================
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao = $_POST['acao'];
    
    // MARCAR AULAS SELECIONADAS COMO CONCLUÍDAS
    if ($acao === 'marcar_concluidas' && isset($_POST['aulas_selecionadas'])) {
        $aulas_ids = $_POST['aulas_selecionadas'];
        if (!empty($aulas_ids)) {
            try {
                $conn->beginTransaction();
                
                // Usar apenas parâmetros posicionais
                $placeholders = implode(',', array_fill(0, count($aulas_ids), '?'));
                
                // Buscar cursos afetados
                $sql_cursos = "SELECT DISTINCT id_curso FROM cronograma WHERE id_aula IN ($placeholders) AND id_cliente = ?";
                $stmt_cursos = $conn->prepare($sql_cursos);
                $params_cursos = array_merge($aulas_ids, [$id_cliente]);
                $stmt_cursos->execute($params_cursos);
                $cursos_afetados = $stmt_cursos->fetchAll(PDO::FETCH_COLUMN);
                
                // Atualizar aulas
                $sql = "UPDATE cronograma 
                        SET status_aula = 'realizada', 
                            observacao = CONCAT(IFNULL(observacao, ''), ' | Marcada como concluída em ', NOW())
                        WHERE id_aula IN ($placeholders)
                        AND id_cliente = ?
                        AND id_unidade = ?";
                
                $stmt = $conn->prepare($sql);
                $params = array_merge($aulas_ids, [$id_cliente, $id_unidade]);
                $stmt->execute($params);
                $qtd = $stmt->rowCount();
                
                foreach ($cursos_afetados as $id_curso) {
                    atualizarPercentualCurso($conn, $id_curso, $id_cliente, $id_unidade);
                }
                
                $conn->commit();
                $mensagem = "$qtd aula(s) marcada(s) como concluída(s) com sucesso!";
                $tipo_mensagem = 'success';
            } catch (PDOException $e) {
                $conn->rollBack();
                $mensagem = 'Erro ao marcar aulas: ' . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        } else {
            $mensagem = 'Nenhuma aula selecionada.';
            $tipo_mensagem = 'warning';
        }
    }
    
    // MARCAR TODAS AS AULAS DO DIA COMO CONCLUÍDAS
    elseif ($acao === 'marcar_todas_dia') {
        try {
            $data_atual = date('Y-m-d');
            
            $conn->beginTransaction();
            
            // Buscar cursos afetados
            $sql_cursos = "SELECT DISTINCT id_curso FROM cronograma 
                           WHERE data_aula = :data 
                           AND status_aula = 'agendada'
                           AND id_cliente = :id_cliente
                           AND id_unidade = :id_unidade";
            $stmt_cursos = $conn->prepare($sql_cursos);
            $stmt_cursos->execute([
                ':data' => $data_atual,
                ':id_cliente' => $id_cliente,
                ':id_unidade' => $id_unidade
            ]);
            $cursos_afetados = $stmt_cursos->fetchAll(PDO::FETCH_COLUMN);
            
            // Atualizar aulas
            $sql = "UPDATE cronograma 
                    SET status_aula = 'realizada',
                        observacao = CONCAT(IFNULL(observacao, ''), ' | Todas as aulas do dia marcadas como concluídas em ', NOW())
                    WHERE data_aula = :data 
                    AND status_aula = 'agendada'
                    AND id_cliente = :id_cliente
                    AND id_unidade = :id_unidade";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':data' => $data_atual,
                ':id_cliente' => $id_cliente,
                ':id_unidade' => $id_unidade
            ]);
            $qtd = $stmt->rowCount();
            
            foreach ($cursos_afetados as $id_curso) {
                atualizarPercentualCurso($conn, $id_curso, $id_cliente, $id_unidade);
            }
            
            $conn->commit();
            $mensagem = "$qtd aula(s) do dia marcada(s) como concluída(s) com sucesso!";
            $tipo_mensagem = 'success';
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = 'Erro ao marcar aulas: ' . $e->getMessage();
            $tipo_mensagem = 'danger';
        }
    }
    
    // MARCAR AULAS ANTERIORES COMO CONCLUÍDAS
    elseif ($acao === 'marcar_anteriores_concluidas' && isset($_POST['aulas_anteriores_selecionadas'])) {
        $aulas_ids = $_POST['aulas_anteriores_selecionadas'];
        if (!empty($aulas_ids)) {
            try {
                $conn->beginTransaction();
                
                $placeholders = implode(',', array_fill(0, count($aulas_ids), '?'));
                
                // Buscar cursos afetados
                $sql_cursos = "SELECT DISTINCT id_curso FROM cronograma WHERE id_aula IN ($placeholders) AND id_cliente = ?";
                $stmt_cursos = $conn->prepare($sql_cursos);
                $params_cursos = array_merge($aulas_ids, [$id_cliente]);
                $stmt_cursos->execute($params_cursos);
                $cursos_afetados = $stmt_cursos->fetchAll(PDO::FETCH_COLUMN);
                
                // Atualizar aulas
                $sql = "UPDATE cronograma 
                        SET status_aula = 'realizada', 
                            observacao = CONCAT(IFNULL(observacao, ''), ' | Marcada como concluída retroativamente em ', NOW())
                        WHERE id_aula IN ($placeholders)
                        AND id_cliente = ?
                        AND id_unidade = ?";
                
                $stmt = $conn->prepare($sql);
                $params = array_merge($aulas_ids, [$id_cliente, $id_unidade]);
                $stmt->execute($params);
                $qtd = $stmt->rowCount();
                
                foreach ($cursos_afetados as $id_curso) {
                    atualizarPercentualCurso($conn, $id_curso, $id_cliente, $id_unidade);
                }
                
                $conn->commit();
                $mensagem = "$qtd aula(s) anteriores marcada(s) como concluída(s) com sucesso!";
                $tipo_mensagem = 'success';
            } catch (PDOException $e) {
                $conn->rollBack();
                $mensagem = 'Erro ao marcar aulas: ' . $e->getMessage();
                $tipo_mensagem = 'danger';
            }
        } else {
            $mensagem = 'Nenhuma aula anterior selecionada.';
            $tipo_mensagem = 'warning';
        }
    }
}

// ============================================================
// FUNÇÃO PARA ATUALIZAR PERCENTUAL DO CURSO (MODIFICADA)
// ============================================================
function atualizarPercentualCurso($conn, $id_curso, $id_cliente, $id_unidade) {
    try {
        $sql_total = "SELECT COUNT(*) FROM cronograma 
                      WHERE id_curso = :id_curso 
                      AND id_cliente = :id_cliente
                      AND id_unidade = :id_unidade";
        $stmt = $conn->prepare($sql_total);
        $stmt->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente,
            ':id_unidade' => $id_unidade
        ]);
        $total_aulas = (int)$stmt->fetchColumn();
        
        $sql_realizadas = "SELECT COUNT(*) FROM cronograma 
                           WHERE id_curso = :id_curso 
                           AND status_aula = 'realizada'
                           AND id_cliente = :id_cliente
                           AND id_unidade = :id_unidade";
        $stmt = $conn->prepare($sql_realizadas);
        $stmt->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente,
            ':id_unidade' => $id_unidade
        ]);
        $aulas_realizadas = (int)$stmt->fetchColumn();
        
        if ($total_aulas > 0) {
            $percentual = round(($aulas_realizadas / $total_aulas) * 100, 2);
        } else {
            $percentual = 0;
        }
        
        $sql_update = "UPDATE cursos SET percentual_conclusao = :percentual 
                       WHERE id_curso = :id_curso 
                       AND id_cliente = :id_cliente";
        $stmt = $conn->prepare($sql_update);
        $stmt->execute([
            ':percentual' => $percentual,
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// INICIALIZAR VARIÁVEIS
// ============================================================
$total_hoje = 0;
$concluidas_hoje = 0;
$pendentes_hoje = 0;
$aulas_hoje = [];
$cursos_ativos = [];
$cursos_para_concluir = [];
$aulas_aguardando = [];
$aulas_anteriores_pendentes = [];

// ============================================================
// BUSCAR DADOS DO BANCO - FILTRADOS POR CLIENTE E UNIDADE
// ============================================================
try {
    $data_atual = date('Y-m-d');
    $data_hoje = date('d/m/Y');
    
    // ============================================================
    // 1. RESUMO RÁPIDO - FILTRADO POR CLIENTE E UNIDADE
    // ============================================================
    $sql_total_hoje = "SELECT COUNT(*) as total FROM cronograma 
                       WHERE data_aula = :data 
                       AND id_cliente = :id_cliente 
                       AND id_unidade = :id_unidade";
    $stmt = $conn->prepare($sql_total_hoje);
    $stmt->execute([
        ':data' => $data_atual,
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $total_hoje = (int)$stmt->fetchColumn();
    
    $sql_concluidas_hoje = "SELECT COUNT(*) as total FROM cronograma 
                            WHERE data_aula = :data 
                            AND status_aula = 'realizada' 
                            AND id_cliente = :id_cliente
                            AND id_unidade = :id_unidade";
    $stmt = $conn->prepare($sql_concluidas_hoje);
    $stmt->execute([
        ':data' => $data_atual,
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $concluidas_hoje = (int)$stmt->fetchColumn();
    
    $pendentes_hoje = $total_hoje - $concluidas_hoje;
    
    // ============================================================
    // 2. AULAS DO DIA - FILTRADO POR CLIENTE E UNIDADE
    // ============================================================
    $sql_aulas = "SELECT c.*, 
                         cu.nome_curso,
                         cu.numero_curso,
                         f.nome_funcionario AS nome_professor,
                         s.numero_sala
                  FROM cronograma c
                  LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                  LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
                  LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
                  WHERE c.data_aula = :data 
                  AND c.id_cliente = :id_cliente
                  AND c.id_unidade = :id_unidade
                  ORDER BY c.horario_inicio ASC, c.id_aula ASC";
    
    $stmt = $conn->prepare($sql_aulas);
    $stmt->execute([
        ':data' => $data_atual,
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $aulas_hoje = $stmt->fetchAll();
    
    // ============================================================
    // 3. PROGRESSO DOS CURSOS
    // ============================================================
    $sql_cursos = "SELECT 
                        c.id_curso,
                        c.numero_curso,
                        c.nome_curso,
                        c.status_curso,
                        c.id_unidade,
                        c.percentual_conclusao,
                        c.id_docente
                    FROM cursos c
                    WHERE c.status_curso = 'ativo'
                    AND c.id_cliente = :id_cliente
                    AND c.id_unidade = :id_unidade
                    ORDER BY c.nome_curso ASC";
    
    $stmt = $conn->prepare($sql_cursos);
    $stmt->execute([
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $cursos_raw = $stmt->fetchAll();
    
    $cursos_ativos = [];
    foreach ($cursos_raw as $curso) {
        $nome_professor = 'Não definido';
        if (!empty($curso['id_docente'])) {
            $sql_prof = "SELECT nome_funcionario FROM funcionarios 
                         WHERE id_funcionario = :id_docente 
                         AND id_cliente = :id_cliente";
            $stmt_prof = $conn->prepare($sql_prof);
            $stmt_prof->execute([
                ':id_docente' => $curso['id_docente'],
                ':id_cliente' => $id_cliente
            ]);
            $nome_professor = $stmt_prof->fetchColumn();
            if (!$nome_professor) {
                $nome_professor = 'Não definido';
            }
        }
        
        $sql_total_aulas = "SELECT COUNT(*) FROM cronograma 
                            WHERE id_curso = :id_curso 
                            AND id_cliente = :id_cliente
                            AND id_unidade = :id_unidade";
        $stmt_total = $conn->prepare($sql_total_aulas);
        $stmt_total->execute([
            ':id_curso' => $curso['id_curso'],
            ':id_cliente' => $id_cliente,
            ':id_unidade' => $id_unidade
        ]);
        $total_aulas = (int)$stmt_total->fetchColumn();
        
        $sql_realizadas = "SELECT COUNT(*) FROM cronograma 
                           WHERE id_curso = :id_curso 
                           AND status_aula = 'realizada'
                           AND id_cliente = :id_cliente
                           AND id_unidade = :id_unidade";
        $stmt_realizadas = $conn->prepare($sql_realizadas);
        $stmt_realizadas->execute([
            ':id_curso' => $curso['id_curso'],
            ':id_cliente' => $id_cliente,
            ':id_unidade' => $id_unidade
        ]);
        $aulas_realizadas = (int)$stmt_realizadas->fetchColumn();
        
        if ($total_aulas > 0) {
            $percentual = round(($aulas_realizadas / $total_aulas) * 100, 2);
        } else {
            $percentual = 0;
        }
        
        $cursos_ativos[] = [
            'id_curso' => $curso['id_curso'],
            'numero_curso' => $curso['numero_curso'],
            'nome_curso' => $curso['nome_curso'],
            'status_curso' => $curso['status_curso'],
            'id_unidade' => $curso['id_unidade'],
            'percentual_conclusao' => $curso['percentual_conclusao'],
            'nome_professor' => $nome_professor,
            'total_aulas' => $total_aulas,
            'aulas_realizadas' => $aulas_realizadas,
            'percentual' => $percentual
        ];
    }
    
    // ============================================================
    // 4. CURSOS COM 100% PARA CONCLUIR
    // ============================================================
    $cursos_para_concluir = array_filter($cursos_ativos, function($c) {
        return $c['percentual'] >= 100 && $c['total_aulas'] > 0;
    });
    
    // ============================================================
    // 5. AULAS AGUARDANDO REMARCAÇÃO
    // ============================================================
    $sql_aulas_aguardando = "SELECT c.*, 
                                     cu.nome_curso,
                                     cu.numero_curso,
                                     cu.status_curso,
                                     f.nome_funcionario AS nome_professor,
                                     s.numero_sala,
                                     u.nome_unidade
                              FROM cronograma c
                              LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                              LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
                              LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
                              LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                              WHERE c.status_aula = 'aguardando_remarcacao'
                              AND cu.status_curso = 'ativo'
                              AND c.id_cliente = :id_cliente
                              AND c.id_unidade = :id_unidade
                              ORDER BY c.data_aula ASC, c.horario_inicio ASC";
    
    $stmt = $conn->prepare($sql_aulas_aguardando);
    $stmt->execute([
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $aulas_aguardando = $stmt->fetchAll();
    
    // ============================================================
    // 6. AULAS ANTERIORES NÃO CONCLUÍDAS
    // ============================================================
    $sql_aulas_anteriores = "SELECT c.*, 
                                    cu.nome_curso,
                                    cu.numero_curso,
                                    cu.status_curso,
                                    f.nome_funcionario AS nome_professor,
                                    s.numero_sala,
                                    u.nome_unidade
                             FROM cronograma c
                             LEFT JOIN cursos cu ON c.id_curso = cu.id_curso AND cu.id_cliente = c.id_cliente
                             LEFT JOIN funcionarios f ON c.id_professor = f.id_funcionario AND f.id_cliente = c.id_cliente
                             LEFT JOIN salas s ON c.id_sala = s.id_sala AND s.id_cliente = c.id_cliente
                             LEFT JOIN unidades u ON c.id_unidade = u.id_unidade AND u.id_cliente = c.id_cliente
                             WHERE c.data_aula < :data_atual
                               AND c.status_aula NOT IN ('realizada', 'cancelada')
                               AND c.id_cliente = :id_cliente
                               AND c.id_unidade = :id_unidade
                               AND cu.status_curso = 'ativo'
                             ORDER BY c.data_aula DESC, c.horario_inicio DESC";
    
    $stmt = $conn->prepare($sql_aulas_anteriores);
    $stmt->execute([
        ':data_atual' => date('Y-m-d'),
        ':id_cliente' => $id_cliente,
        ':id_unidade' => $id_unidade
    ]);
    $aulas_anteriores_pendentes = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $mensagem = 'Erro ao carregar dados: ' . $e->getMessage();
    $tipo_mensagem = 'danger';
    $aulas_hoje = [];
    $cursos_ativos = [];
    $cursos_para_concluir = [];
    $aulas_aguardando = [];
    $aulas_anteriores_pendentes = [];
}

$titulo = 'Painel do Coordenador - Gerenciamento de Ambientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?php echo $titulo; ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet"/>

    <style>
        /* ======================================================
           RESET & BASE
        ====================================================== */
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

        /* ======================================================
           SIDEBAR
        ====================================================== */
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
        .logo-text span {
            color: #1a73e8;
        }
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

        .menu-item i {
            width: 20px;
            font-size: 16px;
            color: #8a9bb5;
            transition: color 0.15s;
        }

        .menu-item:hover {
            background: #f0f6ff;
            color: #1a2639;
        }
        .menu-item:hover i {
            color: #1a73e8;
        }

        .menu-item.active {
            background: #1a73e8;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
        }
        .menu-item.active i {
            color: #ffffff;
        }

        .menu-item .badge-menu {
            margin-left: auto;
            background: #ff6b6b;
            color: #fff;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 60px;
            font-weight: 600;
        }

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

        .user-info {
            line-height: 1.3;
        }
        .user-info .name {
            font-weight: 600;
            font-size: 13px;
            color: #1a2639;
        }
        .user-info .role {
            font-size: 12px;
            color: #8a9bb5;
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

        /* ======================================================
           MAIN CONTENT
        ====================================================== */
        .main {
            flex: 1;
            padding: 28px 36px 20px;
            overflow-y: auto;
            background: #f0f4fb;
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
        .page-subtitle {
            font-size: 14px;
            color: #7a8aa0;
            margin-bottom: 0;
        }
        .page-subtitle strong {
            color: #1a73e8;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
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
            border: 1px solid transparent;
        }
        .alert-success {
            background: #e6f7e9;
            color: #1e8546;
            border-color: #c8f0cf;
        }
        .alert-danger {
            background: #ffe9e9;
            color: #b33a3a;
            border-color: #ffd6d6;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffc107;
        }
        .alert i {
            font-size: 18px;
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
            background: #ffffff;
            color: #1a2639;
            border: 1px solid #e2e9f3;
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
            transform: translateY(-1px);
        }

        .btn-success {
            background: #34a853;
            color: #ffffff;
            border: none;
        }
        .btn-success:hover {
            background: #2d9248;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #dc3545;
            color: #ffffff;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: #f9ab00;
            color: #ffffff;
            border: none;
        }
        .btn-warning:hover {
            background: #e09a00;
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        .resumo-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .resumo-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
        }
        .resumo-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        }

        .resumo-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .resumo-card .icon.blue {
            background: #e8f0fe;
            color: #1a73e8;
        }
        .resumo-card .icon.green {
            background: #e6f7e9;
            color: #34a853;
        }
        .resumo-card .icon.orange {
            background: #fff3e0;
            color: #f9ab00;
        }
        .resumo-card .icon.red {
            background: #ffe9e9;
            color: #dc3545;
        }
        .resumo-card .icon.purple {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .resumo-card .info {
            flex: 1;
        }
        .resumo-card .info .number {
            font-size: 24px;
            font-weight: 700;
            color: #0e1a2b;
            line-height: 1.2;
        }
        .resumo-card .info .label {
            font-size: 13px;
            color: #7a8aa0;
            font-weight: 500;
        }

        .section {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #ebf0f8;
            padding: 24px 28px;
            margin-bottom: 24px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .section-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #0e1a2b;
        }
        .section-header h3 i {
            color: #1a73e8;
            margin-right: 8px;
        }

        .section-header .badge-count {
            background: #1a73e8;
            color: #fff;
            padding: 2px 10px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 600;
        }
        .section-header .badge-count.success {
            background: #34a853;
        }
        .section-header .badge-count.warning {
            background: #f9ab00;
        }
        .section-header .badge-count.danger {
            background: #dc3545;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .table thead {
            background: #f9fbfe;
            border-bottom: 2px solid #eef3fa;
        }

        .table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #5a6a7e;
            font-weight: 600;
            white-space: nowrap;
        }

        .table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f4fc;
            color: #1a2639;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #fafcff;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table .checkbox-row {
            width: 30px;
            text-align: center;
        }
        .table .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #1a73e8;
        }

        .badge-status {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 60px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-status.concluida {
            background: #e6f7e9;
            color: #1e8546;
        }
        .badge-status.pendente {
            background: #fff2e0;
            color: #b86a1f;
        }
        .badge-status.cancelada {
            background: #ffe9e9;
            color: #b33a3a;
        }
        .badge-status.aguardando {
            background: #e3f2fd;
            color: #0d47a1;
        }
        .badge-status.atrasada {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }

        .progress-bar-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-bar {
            width: 120px;
            height: 8px;
            background: #e2e9f3;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar .fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-label {
            font-weight: 600;
            font-size: 13px;
            min-width: 45px;
        }

        .actions-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f4fb;
        }

        .btn-action-sm {
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 60px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: none;
            cursor: pointer;
        }
        .btn-action-sm.btn-edit {
            background: #e8f0fe;
            color: #1a73e8;
        }
        .btn-action-sm.btn-edit:hover {
            background: #d0dcfa;
        }
        .btn-action-sm.btn-success {
            background: #e6f7e9;
            color: #1e8546;
        }
        .btn-action-sm.btn-success:hover {
            background: #c8f0cf;
        }
        .btn-action-sm.btn-warning {
            background: #fff3e0;
            color: #e37400;
        }
        .btn-action-sm.btn-warning:hover {
            background: #ffe0b2;
        }
        .btn-action-sm.btn-danger {
            background: #ffebee;
            color: #c62828;
        }
        .btn-action-sm.btn-danger:hover {
            background: #ffcdd2;
        }

        /* ======================================================
           SEÇÃO DE AULAS ANTERIORES - DESTAQUE
        ====================================================== */
        .section-anteriores {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .section-anteriores .section-header h3 i {
            color: #dc3545;
        }
        .section-anteriores .section-header .badge-count {
            background: #dc3545;
        }

        @media (max-width: 1200px) {
            .resumo-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .resumo-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 820px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -300px;
                width: 280px;
                height: 100vh;
                z-index: 999;
                transition: left 0.3s ease;
                padding-top: 70px;
            }
            .sidebar.open {
                left: 0;
            }
            .main {
                padding: 16px 18px;
            }
            .resumo-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .resumo-card {
                padding: 16px;
            }
        }

        @media (max-width: 540px) {
            .main {
                padding: 12px 14px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .header-actions {
                width: 100%;
            }
            .header-actions .btn {
                flex: 1;
                justify-content: center;
                font-size: 12px;
                padding: 8px 12px;
            }
            .resumo-grid {
                grid-template-columns: 1fr;
            }
            .section {
                padding: 16px;
            }
            .table {
                font-size: 12px;
            }
            .table th,
            .table td {
                padding: 6px 8px;
            }
            .progress-bar {
                width: 80px;
            }
        }
    </style>
</head>
<body>

    <?php include_once __DIR__ . '/../INCLUDES/sidebar.php'; ?>

    <main class="main">

        <header class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-chart-pie"></i> Painel do Coordenador</h1>
                <p class="page-subtitle">
                    Gestão de Aulas e Progresso dos Cursos - 
                    <strong><?php echo htmlspecialchars($nome_unidade); ?></strong>
                </p>
            </div>
            <div class="header-actions">
                <span style="font-size: 13px; color: #7a8aa0;">
                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($nome_cliente); ?>
                </span>
            </div>
        </header>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?>">
                <i class="fas fa-<?php echo $tipo_mensagem === 'success' ? 'check-circle' : ($tipo_mensagem === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo $mensagem; ?>
            </div>
        <?php endif; ?>

        <!-- RESUMO RÁPIDO -->
        <div class="resumo-grid">
            <div class="resumo-card">
                <div class="icon blue"><i class="fas fa-book"></i></div>
                <div class="info">
                    <div class="number"><?php echo $total_hoje; ?></div>
                    <div class="label"><i class="fa-solid fa-list"></i> Total Aulas Hoje</div>
                </div>
            </div>
            <div class="resumo-card">
                <div class="icon green"><i class="fas fa-check-circle"></i></div>
                <div class="info">
                    <div class="number"><?php echo $concluidas_hoje; ?></div>
                    <div class="label"><i class="fas fa-check"></i> Concluídas Hoje</div>
                </div>
            </div>
            <div class="resumo-card">
                <div class="icon orange"><i class="fas fa-clock"></i></div>
                <div class="info">
                    <div class="number"><?php echo $pendentes_hoje; ?></div>
                    <div class="label"><i class="fas fa-hourglass-half"></i> Pendentes Hoje</div>
                </div>
            </div>
            <div class="resumo-card">
                <div class="icon red"><i class="fas fa-hourglass-half"></i></div>
                <div class="info">
                    <div class="number"><?php echo count($aulas_aguardando); ?></div>
                    <div class="label"><i class="fas fa-clock"></i> Aguardando Remarcação</div>
                </div>
            </div>
            <div class="resumo-card">
                <div class="icon purple"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="info">
                    <div class="number" style="color: #c62828;"><?php echo count($aulas_anteriores_pendentes); ?></div>
                    <div class="label"><i class="fas fa-calendar-times"></i> Aulas Atrasadas</div>
                </div>
            </div>
        </div>

        <!-- LISTA 1: AULAS DO DIA -->
        <div class="section">
            <div class="section-header">
                <h3><i class="fas fa-calendar-day"></i> Aulas do Dia - <?php echo $data_hoje ?? date('d/m/Y'); ?></h3>
                <span class="badge-count"><i class="fas fa-book"></i> <?php echo $total_hoje; ?> aulas</span>
            </div>

            <form method="POST" action="" id="formAulas">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="checkbox-row">
                                    <input type="checkbox" id="selecionar_todas" title="Selecionar todas">
                                </th>
                                <th>#</th>
                                <th><i class="fas fa-graduation-cap"></i> Curso</th>
                                <th><i class="fas fa-user-tie"></i> Professor</th>
                                <th><i class="fas fa-door-open"></i> Sala</th>
                                <th><i class="fas fa-clock"></i> Horário</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($aulas_hoje)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px; color: #7a8aa0;">
                                        <i class="fas fa-calendar" style="font-size: 30px; display: block; margin-bottom: 8px;"></i>
                                        Nenhuma aula agendada para hoje na sua unidade.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $contador = 1; ?>
                                <?php foreach ($aulas_hoje as $aula): ?>
                                    <tr>
                                        <td class="checkbox-row">
                                            <?php if ($aula['status_aula'] === 'agendada'): ?>
                                                <input type="checkbox" name="aulas_selecionadas[]" value="<?php echo $aula['id_aula']; ?>" class="aula-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo str_pad($contador++, 3, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($aula['nome_curso'] ?? 'N/A'); ?></strong>
                                            <br><small style="color: #7a8aa0;"><?php echo htmlspecialchars($aula['numero_curso'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($aula['nome_professor'] ?? 'Não definido'); ?></td>
                                        <td><?php echo htmlspecialchars($aula['numero_sala'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                                $hora_inicio = date('H:i', strtotime($aula['horario_inicio']));
                                                $hora_fim = date('H:i', strtotime($aula['horario_fim']));
                                                echo $hora_inicio . ' - ' . $hora_fim;
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $aula['status_aula'];
                                            $classe = '';
                                            $texto = '';
                                            
                                            if ($status === 'realizada') {
                                                $classe = 'concluida';
                                                $texto = '<i class="fas fa-check-circle"></i> Concluída';
                                            } elseif ($status === 'agendada') {
                                                $classe = 'pendente';
                                                $texto = '<i class="fas fa-clock"></i> Pendente';
                                            } elseif ($status === 'cancelada') {
                                                $classe = 'cancelada';
                                                $texto = '<i class="fas fa-times-circle"></i> Cancelada';
                                            } elseif ($status === 'aguardando_remarcacao') {
                                                $classe = 'aguardando';
                                                $texto = '<i class="fas fa-hourglass-half"></i> Aguardando';
                                            } else {
                                                $texto = $status;
                                            }
                                            ?>
                                            <span class="badge-status <?php echo $classe; ?>"><?php echo $texto; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($aulas_hoje)): ?>
                <div class="actions-group">
                    <button type="submit" name="acao" value="marcar_concluidas" class="btn btn-success">
                        <i class="fas fa-check-double"></i> Marcar Selecionadas como Concluídas
                    </button>
                    <button type="submit" name="acao" value="marcar_todas_dia" class="btn btn-primary" 
                            onclick="return confirm('Tem certeza que deseja marcar TODAS as aulas do dia como concluídas?')">
                        <i class="fas fa-check-circle"></i> Marcar TODAS do Dia como Concluídas
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- LISTA 5: AULAS ANTERIORES NÃO CONCLUÍDAS -->
        <?php if (!empty($aulas_anteriores_pendentes)): ?>
        <div class="section section-anteriores">
            <div class="section-header">
                <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Aulas Anteriores Não Concluídas</h3>
                <span class="badge-count danger"><i class="fas fa-calendar-times"></i> <?php echo count($aulas_anteriores_pendentes); ?> aulas atrasadas</span>
            </div>

            <div style="background: #fff3cd; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; border-left: 4px solid #ffc107;">
                <i class="fas fa-info-circle" style="color: #856404;"></i>
                <span style="color: #856404; font-size: 13px;">
                    Estas aulas estão com data <strong>anterior</strong> ao dia atual e ainda não foram marcadas como concluídas.
                    Recomenda-se <strong>revisar</strong> cada uma e marcar as que foram realizadas.
                </span>
            </div>

            <form method="POST" action="" id="formAulasAnteriores">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="checkbox-row">
                                    <input type="checkbox" id="selecionar_todas_anteriores" title="Selecionar todas">
                                </th>
                                <th>#</th>
                                <th><i class="fas fa-graduation-cap"></i> Curso</th>
                                <th><i class="fas fa-user-tie"></i> Professor</th>
                                <th><i class="fas fa-door-open"></i> Sala</th>
                                <th><i class="fas fa-calendar-day"></i> Data</th>
                                <th><i class="fas fa-clock"></i> Horário</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $contador = 1; ?>
                            <?php foreach ($aulas_anteriores_pendentes as $aula): 
                                $hora_inicio = date('H:i', strtotime($aula['horario_inicio']));
                                $hora_fim = date('H:i', strtotime($aula['horario_fim']));
                                $data_aula = date('d/m/Y', strtotime($aula['data_aula']));
                                
                                // Calcular dias de atraso
                                $dataAulaObj = new DateTime($aula['data_aula']);
                                $hojeObj = new DateTime();
                                $diasAtraso = $hojeObj->diff($dataAulaObj)->days;
                                
                                // Status do curso
                                $statusCurso = $aula['status_curso'] ?? 'ativo';
                                $podeMarcar = ($statusCurso === 'ativo');
                            ?>
                                <tr>
                                    <td class="checkbox-row">
                                        <?php if ($podeMarcar): ?>
                                            <input type="checkbox" name="aulas_anteriores_selecionadas[]" value="<?php echo $aula['id_aula']; ?>" class="aula-anterior-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo str_pad($contador++, 3, '0', STR_PAD_LEFT); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aula['nome_curso'] ?? 'N/A'); ?></strong>
                                        <br><small style="color: #7a8aa0;"><?php echo htmlspecialchars($aula['numero_curso'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($aula['nome_professor'])): ?>
                                            <span style="color: #1a2639;">
                                                <i class="fas fa-user-tie" style="color: #1a73e8;"></i>
                                                <?php echo htmlspecialchars($aula['nome_professor']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999; font-style: italic;">
                                                <i class="fas fa-user-slash"></i> Não definido
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500;">
                                            <i class="fas fa-door-open" style="color: #1a73e8;"></i>
                                            <?php echo htmlspecialchars($aula['numero_sala'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500; color: #c62828;">
                                            <i class="fas fa-calendar-day" style="color: #dc3545;"></i>
                                            <?php echo $data_aula; ?>
                                            <span style="font-size: 11px; background: #ffebee; padding: 1px 8px; border-radius: 10px; margin-left: 4px;">
                                                -<?php echo $diasAtraso; ?> dias
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 500;">
                                            <i class="fas fa-clock" style="color: #1a73e8;"></i>
                                            <?php echo $hora_inicio . ' - ' . $hora_fim; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status = $aula['status_aula'];
                                        $classe = '';
                                        $texto = '';
                                        
                                        if ($status === 'realizada') {
                                            $classe = 'concluida';
                                            $texto = '<i class="fas fa-check-circle"></i> Concluída';
                                        } elseif ($status === 'agendada') {
                                            $classe = 'atrasada';
                                            $texto = '<i class="fas fa-exclamation-circle"></i> Atrasada';
                                        } elseif ($status === 'cancelada') {
                                            $classe = 'cancelada';
                                            $texto = '<i class="fas fa-times-circle"></i> Cancelada';
                                        } elseif ($status === 'aguardando_remarcacao') {
                                            $classe = 'aguardando';
                                            $texto = '<i class="fas fa-hourglass-half"></i> Aguardando';
                                        } else {
                                            $texto = $status;
                                        }
                                        ?>
                                        <span class="badge-status <?php echo $classe; ?>"><?php echo $texto; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="actions-group">
                    <button type="submit" name="acao" value="marcar_anteriores_concluidas" class="btn btn-success">
                        <i class="fas fa-check-double"></i> Marcar Selecionadas como Concluídas
                    </button>
                    <span style="font-size: 12px; color: #7a8aa0; display: flex; align-items: center; gap: 4px; margin-left: 8px;">
                        <i class="fas fa-info-circle"></i>
                        Apenas aulas de cursos <strong>ativos</strong> podem ser marcadas.
                    </span>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- LISTA 2: PROGRESSO DOS CURSOS -->
        <div class="section">
            <div class="section-header">
                <h3><i class="fas fa-chart-line"></i> Progresso dos Cursos</h3>
                <span class="badge-count"><i class="fas fa-graduation-cap"></i> <?php echo count($cursos_ativos); ?> cursos ativos</span>
            </div>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-graduation-cap"></i> Curso</th>
                            <th><i class="fas fa-user-tie"></i> Professor</th>
                            <th><i class="fas fa-check-double"></i> Aulas Realizadas</th>
                            <th><i class="fas fa-chart-simple"></i> Progresso</th>
                            <th><i class="fas fa-info-circle"></i> Status</th>
                            <th><i class="fas fa-cogs"></i> Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cursos_ativos)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px; color: #7a8aa0;">
                                    <i class="fas fa-info-circle" style="font-size: 30px; display: block; margin-bottom: 8px;"></i>
                                    Nenhum curso ativo na sua unidade.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cursos_ativos as $curso): 
                                $perc = $curso['percentual'] ?? 0;
                                $cor = $perc >= 80 ? '#34a853' : ($perc >= 40 ? '#f9ab00' : '#dc3545');
                                $total = $curso['total_aulas'] ?? 0;
                                $realizadas = $curso['aulas_realizadas'] ?? 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($curso['nome_curso']); ?></strong>
                                        <br><small style="color: #7a8aa0;"><?php echo htmlspecialchars($curso['numero_curso']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($curso['nome_professor'] ?? 'Não definido'); ?></td>
                                    <td><?php echo $realizadas . '/' . $total; ?></td>
                                    <td>
                                        <div class="progress-bar-wrapper">
                                            <div class="progress-bar">
                                                <div class="fill" style="width: <?php echo $perc; ?>%; background: <?php echo $cor; ?>;"></div>
                                            </div>
                                            <span class="progress-label"><?php echo number_format($perc, 1); ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($perc >= 100 && $total > 0): ?>
                                            <span class="badge-status concluida"><i class="fas fa-check-circle"></i> Concluído</span>
                                        <?php elseif ($perc >= 60): ?>
                                            <span class="badge-status" style="background: #e3f2fd; color: #0d47a1;"><i class="fas fa-sync"></i> Em andamento</span>
                                        <?php elseif ($perc > 0): ?>
                                            <span class="badge-status" style="background: #fff3e0; color: #e37400;"><i class="fas fa-play"></i> Iniciado</span>
                                        <?php else: ?>
                                            <span class="badge-status" style="background: #f0f4fb; color: #7a8aa0;"><i class="fas fa-hourglass-start"></i> Aguardando</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="../CRONOGRAMA_AULAS/detalhes_curso.php?id=<?php echo $curso['id_curso']; ?>" class="btn-action-sm btn-edit">
                                            <i class="fas fa-eye"></i> Detalhes
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- LISTA 3: CURSOS COM 100% PARA CONCLUIR -->
        <?php if (!empty($cursos_para_concluir)): ?>
        <div class="section" style="border-color: #34a853; background: #f0faf3;">
            <div class="section-header">
                <h3><i class="fas fa-flag-checkered" style="color: #34a853;"></i> Cursos com 100% para Concluir</h3>
                <span class="badge-count success"><i class="fas fa-check-circle"></i> <?php echo count($cursos_para_concluir); ?> cursos</span>
            </div>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-graduation-cap"></i> Curso</th>
                            <th><i class="fas fa-user-tie"></i> Professor</th>
                            <th><i class="fas fa-check-double"></i> Aulas Realizadas</th>
                            <th><i class="fas fa-chart-simple"></i> Progresso</th>
                            <th><i class="fas fa-cogs"></i> Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cursos_para_concluir as $curso): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($curso['nome_curso']); ?></strong>
                                    <br><small style="color: #7a8aa0;"><?php echo htmlspecialchars($curso['numero_curso']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($curso['nome_professor'] ?? 'Não definido'); ?></td>
                                <td><?php echo ($curso['aulas_realizadas'] ?? 0) . '/' . ($curso['total_aulas'] ?? 0); ?></td>
                                <td>
                                    <div class="progress-bar-wrapper">
                                        <div class="progress-bar">
                                            <div class="fill" style="width: 100%; background: #34a853;"></div>
                                        </div>
                                        <span class="progress-label">100%</span>
                                    </div>
                                </td>
                                <td>
                                    <a href="concluir_curso.php?id=<?php echo $curso['id_curso']; ?>" 
                                       class="btn-action-sm btn-success"
                                       onclick="return confirm('Concluir este curso? Todas as aulas foram realizadas.')">
                                        <i class="fas fa-flag-checkered"></i> Concluir Curso
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- LISTA 4: AULAS AGUARDANDO REMARCAÇÃO -->
        <?php if (!empty($aulas_aguardando)): ?>
        <div class="section" style="border-color: #f9ab00; background: #fffbee;">
            <div class="section-header">
                <h3><i class="fas fa-clock" style="color: #f9ab00;"></i> Aulas Aguardando Remarcação</h3>
                <span class="badge-count warning"><i class="fas fa-hourglass-half"></i> <?php echo count($aulas_aguardando); ?> aulas</span>
            </div>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-hashtag"></i> Aula</th>
                            <th><i class="fas fa-graduation-cap"></i> Curso</th>
                            <th><i class="fas fa-user-tie"></i> Professor</th>
                            <th><i class="fas fa-door-open"></i> Sala</th>
                            <th><i class="fas fa-calendar-day"></i> Data</th>
                            <th><i class="fas fa-clock"></i> Horário</th>
                            <th><i class="fas fa-info-circle"></i> Status</th>
                            <th><i class="fas fa-cogs"></i> Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aulas_aguardando as $aula): 
                            $hora_inicio = date('H:i', strtotime($aula['horario_inicio']));
                            $hora_fim = date('H:i', strtotime($aula['horario_fim']));
                            $data_aula = date('d/m/Y', strtotime($aula['data_aula']));
                            
                            // Status do curso
                            $statusCurso = $aula['status_curso'] ?? 'ativo';
                            $podeEditar = ($statusCurso === 'ativo');
                        ?>
                            <tr>
                                <td>
                                    <strong style="color: #1a73e8;">#<?php echo $aula['id_aula']; ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($aula['nome_curso'] ?? 'N/A'); ?></strong>
                                    <br><small style="color: #7a8aa0;"><?php echo htmlspecialchars($aula['numero_curso'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($aula['nome_professor'])): ?>
                                        <span style="color: #1a2639;">
                                            <i class="fas fa-user-tie" style="color: #1a73e8;"></i>
                                            <?php echo htmlspecialchars($aula['nome_professor']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-style: italic;">
                                            <i class="fas fa-user-slash"></i> Não definido
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 500;">
                                        <i class="fas fa-door-open" style="color: #1a73e8;"></i>
                                        <?php echo htmlspecialchars($aula['numero_sala'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 500;">
                                        <i class="fas fa-calendar-day" style="color: #f9ab00;"></i>
                                        <?php echo $data_aula; ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 500;">
                                        <i class="fas fa-clock" style="color: #1a73e8;"></i>
                                        <?php echo $hora_inicio . ' - ' . $hora_fim; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-status aguardando">
                                        <i class="fas fa-hourglass-half"></i> Aguardando
                                    </span>
                                </td>
                                <td>
                                    <?php if ($podeEditar): ?>
                                        <a href="../CRONOGRAMA_AULAS/editar_aula.php?id=<?php echo $aula['id_aula']; ?>" class="btn-action-sm btn-warning">
                                            <i class="fas fa-edit"></i> Remarcar
                                        </a>
                                    <?php else: ?>
                                        <span class="btn-action-sm" style="background: #f0f0f0; color: #999; cursor: not-allowed;">
                                            <i class="fas fa-lock"></i> Bloqueado
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php include_once __DIR__ . '/../INCLUDES/footer.php'; ?>

    </main>

    <script>
        // Selecionar todas as aulas do dia
        document.getElementById('selecionar_todas')?.addEventListener('change', function() {
            document.querySelectorAll('.aula-checkbox').forEach(cb => cb.checked = this.checked);
        });

        // Selecionar todas as aulas anteriores
        document.getElementById('selecionar_todas_anteriores')?.addEventListener('change', function() {
            document.querySelectorAll('.aula-anterior-checkbox').forEach(cb => cb.checked = this.checked);
        });
    </script>

</body>
</html>