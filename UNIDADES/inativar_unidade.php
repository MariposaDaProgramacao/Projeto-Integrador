<?php
// ============================================================
// ARQUIVO: UNIDADES/inativar_unidade.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Inativar uma unidade (exclusão lógica)
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Apenas administradores podem inativar unidades
$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem inativar unidades.');
    redirect('listar_unidade.php');
}

// ============================================================
// VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================
$id_cliente = getClienteId();
$id_usuario = getUsuarioId();

// ============================================================
// RECEBER O ID DA UNIDADE
// ============================================================
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setMessage('error', 'ID da unidade inválido.');
    redirect('listar_unidade.php');
}

// ============================================================
// VERIFICAR SE A UNIDADE PERTENCE AO CLIENTE
// ============================================================
try {
    $sqlCheck = "SELECT id_unidade, nome_unidade, status_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $unidade = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$unidade) {
        setMessage('error', 'Unidade não encontrada ou não pertence à sua organização.');
        redirect('listar_unidade.php');
    }
    
    // Se a unidade já estiver inativa, avisar
    if ($unidade['status_unidade'] === 'inativo') {
        setMessage('warning', 'Esta unidade já está inativa.');
        redirect('listar_unidade.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar unidade: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// ============================================================
// VERIFICAR SE EXISTEM SALAS VINCULADAS A ESTA UNIDADE
// ============================================================
try {
    $sqlSalas = "SELECT COUNT(*) as total FROM salas WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
    $stmtSalas = $conn->prepare($sqlSalas);
    $stmtSalas->execute([
        ':id_unidade' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $totalSalas = (int)$stmtSalas->fetchColumn();
    
    if ($totalSalas > 0) {
        setMessage('warning', "Não é possível inativar esta unidade. Ela possui <strong>{$totalSalas} sala(s)</strong> vinculada(s). Remova ou transfira as salas primeiro.");
        redirect('listar_unidade.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar salas: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// ============================================================
// VERIFICAR SE EXISTEM CURSOS VINCULADOS A ESTA UNIDADE
// ============================================================
try {
    $sqlCursos = "SELECT COUNT(*) as total FROM cursos WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
    $stmtCursos = $conn->prepare($sqlCursos);
    $stmtCursos->execute([
        ':id_unidade' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $totalCursos = (int)$stmtCursos->fetchColumn();
    
    if ($totalCursos > 0) {
        setMessage('warning', "Não é possível inativar esta unidade. Ela possui <strong>{$totalCursos} curso(s)</strong> vinculado(s). Remova ou transfira os cursos primeiro.");
        redirect('listar_unidade.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar cursos: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// ============================================================
// VERIFICAR SE EXISTEM FUNCIONÁRIOS VINCULADOS A ESTA UNIDADE
// ============================================================
try {
    $sqlFuncionarios = "SELECT COUNT(*) as total FROM funcionarios WHERE id_unidade = :id_unidade AND id_cliente = :id_cliente";
    $stmtFuncionarios = $conn->prepare($sqlFuncionarios);
    $stmtFuncionarios->execute([
        ':id_unidade' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $totalFuncionarios = (int)$stmtFuncionarios->fetchColumn();
    
    if ($totalFuncionarios > 0) {
        setMessage('warning', "Não é possível inativar esta unidade. Ela possui <strong>{$totalFuncionarios} funcionário(s)</strong> vinculado(s). Remova ou transfira os funcionários primeiro.");
        redirect('listar_unidade.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar funcionários: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// ============================================================
// INATIVAR A UNIDADE
// ============================================================
try {
    $sql = "UPDATE unidades SET status_unidade = 'inativo' WHERE id_unidade = :id AND id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    
    // ============================================================
    // REGISTRAR NO HISTÓRICO DO SISTEMA
    // ============================================================
    try {
        $sqlHistorico = "INSERT INTO historico_sistema (
            id_funcionario,
            tabela_afetada,
            id_registro_afetado,
            acao,
            dados_novos,
            ip_origem
        ) VALUES (
            :id_funcionario,
            'unidades',
            :id_registro,
            'UPDATE',
            :dados,
            :ip
        )";
        $stmtHistorico = $conn->prepare($sqlHistorico);
        $stmtHistorico->execute([
            ':id_funcionario' => $id_usuario,
            ':id_registro' => $id,
            ':dados' => json_encode([
                'nome_unidade' => $unidade['nome_unidade'],
                'status_anterior' => $unidade['status_unidade'],
                'status_novo' => 'inativo'
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        // Não interrompe o processo
        error_log('Erro ao registrar histórico: ' . $e->getMessage());
    }
    
    setMessage('success', "Unidade <strong>" . htmlspecialchars($unidade['nome_unidade']) . "</strong> inativada com sucesso!");

} catch (PDOException $e) {
    setMessage('error', 'Erro ao inativar unidade: ' . $e->getMessage());
}

// ============================================================
// REDIRECIONAR PARA A LISTAGEM
// ============================================================
redirect('listar_unidade.php');
exit;