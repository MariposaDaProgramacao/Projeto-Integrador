<?php
// ============================================================
// ARQUIVO: CURSOS/inativar_curso.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Inativar um curso (mudar status para 'inativo')
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Apenas administradores podem inativar cursos
$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem inativar cursos.');
    redirect('listar_cursos.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();

// ============================================================
// RECEBER O ID DO CURSO
// ============================================================
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setMessage('error', 'ID do curso inválido.');
    redirect('listar_cursos.php');
}

// ============================================================
// VERIFICAR SE O CURSO EXISTE E PERTENCE AO CLIENTE
// ============================================================
try {
    $sql_check = "SELECT id_curso, nome_curso, status_curso, id_cliente FROM cursos WHERE id_curso = :id AND id_cliente = :id_cliente";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $curso = $stmt_check->fetch();

    if (!$curso) {
        setMessage('error', 'Curso não encontrado.');
        redirect('listar_cursos.php');
    }

    // Se o curso já estiver inativo, avisar
    if ($curso['status_curso'] === 'inativo') {
        setMessage('warning', 'Este curso já está inativo.');
        redirect('listar_cursos.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar curso: ' . $e->getMessage());
    redirect('listar_cursos.php');
}

// ============================================================
// FUNÇÃO PARA REMOVER AULAS DO CURSO (MODIFICADA)
// ============================================================
function removerAulasDoCurso($conn, $id_curso, $id_cliente) {
    try {
        // Buscar quantas aulas serão removidas
        $sqlContar = "SELECT COUNT(*) as total FROM cronograma WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
        $stmtContar = $conn->prepare($sqlContar);
        $stmtContar->execute([
            ':id_curso' => $id_curso,
            ':id_cliente' => $id_cliente
        ]);
        $totalAulas = $stmtContar->fetchColumn();
        
        // Remover todas as aulas do curso
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
            'total_aulas' => $totalAulas,
            'message' => "$aulasRemovidas aula(s) removida(s) permanentemente."
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Erro ao remover aulas: ' . $e->getMessage()];
    }
}

// ============================================================
// INATIVAR O CURSO (MUDAR STATUS PARA 'inativo') E REMOVER AULAS
// ============================================================
try {
    $conn->beginTransaction();
    
    // ============================================================
    // 1. CONTAR AULAS DO CURSO
    // ============================================================
    $sqlContarAulas = "SELECT COUNT(*) as total FROM cronograma WHERE id_curso = :id_curso AND id_cliente = :id_cliente";
    $stmtContarAulas = $conn->prepare($sqlContarAulas);
    $stmtContarAulas->execute([
        ':id_curso' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $totalAulas = $stmtContarAulas->fetchColumn();
    
    // ============================================================
    // 2. REMOVER TODAS AS AULAS DO CURSO
    // ============================================================
    if ($totalAulas > 0) {
        $resultadoRemocao = removerAulasDoCurso($conn, $id, $id_cliente);
        
        if (!$resultadoRemocao['success']) {
            throw new Exception($resultadoRemocao['message']);
        }
        $aulasRemovidas = $resultadoRemocao['aulas_removidas'];
    } else {
        $aulasRemovidas = 0;
    }
    
    // ============================================================
    // 3. ATUALIZAR STATUS DO CURSO PARA INATIVO
    // ============================================================
    $sql = "UPDATE cursos SET status_curso = 'inativo' WHERE id_curso = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    
    // ============================================================
    // 4. REGISTRAR NO HISTÓRICO DO SISTEMA
    // ============================================================
    try {
        $sql_historico = "INSERT INTO historico_sistema (
            id_funcionario,
            tabela_afetada,
            id_registro_afetado,
            acao,
            motivo,
            ip_origem
        ) VALUES (
            :id_funcionario,
            'cursos',
            :id_registro,
            'UPDATE',
            :motivo,
            :ip
        )";
        $stmt_historico = $conn->prepare($sql_historico);
        $stmt_historico->execute([
            ':id_funcionario' => $id_usuario,
            ':id_registro' => $id,
            ':motivo' => "Inativação do curso: " . $curso['nome_curso'] . " (aulas removidas: {$aulasRemovidas})",
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        // Não interrompe o processo se falhar o histórico
        error_log('Erro ao registrar inativação: ' . $e->getMessage());
    }
    
    $conn->commit();
    
    // ============================================================
    // 5. MONTAR MENSAGEM DE SUCESSO
    // ============================================================
    $mensagem = "Curso <strong>" . htmlspecialchars($curso['nome_curso']) . "</strong> inativado com sucesso!";
    
    if ($aulasRemovidas > 0) {
        $mensagem .= " <br>📅 <strong>{$aulasRemovidas} aula(s)</strong> foram removidas permanentemente do banco de dados.";
        $mensagem .= " <br>🔄 As salas foram automaticamente liberadas para outros agendamentos.";
    } else {
        $mensagem .= " <br>📌 O curso não possuía aulas cadastradas.";
    }
    
    setMessage('success', $mensagem);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Erro ao inativar curso: ' . $e->getMessage());
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Erro ao inativar curso: ' . $e->getMessage());
}

// ============================================================
// REDIRECIONAR PARA A LISTAGEM
// ============================================================
redirect('listar_cursos.php');
exit;