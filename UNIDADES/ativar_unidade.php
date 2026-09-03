<?php
// ============================================================
// ARQUIVO: UNIDADES/ativar_unidade.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Ativar uma unidade (mudar status para 'ativo')
// ============================================================

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================
if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// Apenas administradores podem ativar unidades
$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem ativar unidades.');
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
    
    // Se a unidade já estiver ativa, avisar
    if ($unidade['status_unidade'] === 'ativo') {
        setMessage('warning', 'Esta unidade já está ativa.');
        redirect('listar_unidade.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar unidade: ' . $e->getMessage());
    redirect('listar_unidade.php');
}

// ============================================================
// ATIVAR A UNIDADE
// ============================================================
try {
    $sql = "UPDATE unidades SET status_unidade = 'ativo' WHERE id_unidade = :id AND id_cliente = :id_cliente";
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
                'status_novo' => 'ativo'
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        // Não interrompe o processo
        error_log('Erro ao registrar histórico: ' . $e->getMessage());
    }
    
    setMessage('success', "Unidade <strong>" . htmlspecialchars($unidade['nome_unidade']) . "</strong> ativada com sucesso!");

} catch (PDOException $e) {
    setMessage('error', 'Erro ao ativar unidade: ' . $e->getMessage());
}

// ============================================================
// REDIRECIONAR PARA A LISTAGEM
// ============================================================
redirect('listar_unidade.php');
exit;