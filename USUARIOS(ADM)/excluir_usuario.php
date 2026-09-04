<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/excluir_usuario.php
// FUNÇÃO: Excluir profissional (funcionarios) - apenas administradores
// ============================================================

// ============================================================
// 1. INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR LOGIN
// ============================================================

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 3. VERIFICAR PERMISSÃO (APENAS ADMINISTRADOR)
// ============================================================

$tipos_permitidos = ['admin_cliente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores podem excluir profissionais.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA
// ============================================================

$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();

// ============================================================
// 5. RECEBER ID DO FUNCIONÁRIO
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do profissional inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. VERIFICAR SE O FUNCIONÁRIO EXISTE, PERTENCE AO CLIENTE E NÃO É ADMIN
// ============================================================

try {
    // ✅ USANDO TABELA funcionarios
    $sql = "SELECT f.id_funcionario, f.nome_funcionario, f.cargo_funcionario, f.status_acesso, f.email_funcionario 
            FROM funcionarios f
            WHERE f.id_funcionario = :id 
            AND f.id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        setMessage('error', 'Profissional não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    // Não permitir excluir administradores
    if ($usuario['cargo_funcionario'] === 'administrador') {
        setMessage('error', 'Não é possível excluir um administrador.');
        redirect('listar_usuarios.php');
    }

    // Não permitir excluir a si mesmo
    if ($usuario['id_funcionario'] == $id_usuario_logado) {
        setMessage('error', 'Você não pode excluir a si mesmo.');
        redirect('listar_usuarios.php');
    }

    // ============================================================
    // 6.1 Verificar dependências
    // ============================================================

    // Verificar se o profissional é professor em alguma aula
    $sqlCheck = "SELECT COUNT(*) FROM cronograma WHERE id_professor = :id_usuario AND id_cliente = :id_cliente";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([
        ':id_usuario' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $aulas_count = (int)$stmtCheck->fetchColumn();

    if ($aulas_count > 0) {
        setMessage('error', "Não é possível excluir este profissional. Ele é professor em <strong>{$aulas_count} aula(s)</strong>. Remova ou altere o professor das aulas primeiro.");
        redirect('listar_usuarios.php');
    }

    // Verificar se o profissional é docente de algum curso
    $sqlCheck = "SELECT COUNT(*) FROM cursos WHERE id_docente = :id_usuario AND id_cliente = :id_cliente";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([
        ':id_usuario' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $cursos_count = (int)$stmtCheck->fetchColumn();

    if ($cursos_count > 0) {
        setMessage('error', "Não é possível excluir este profissional. Ele é docente em <strong>{$cursos_count} curso(s)</strong>. Remova ou altere o docente dos cursos primeiro.");
        redirect('listar_usuarios.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao verificar profissional: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. EXCLUIR FUNCIONÁRIO
// ============================================================

try {
    $conn->beginTransaction();

    // ✅ Excluir da tabela funcionarios
    $sqlDelete = "DELETE FROM funcionarios 
                  WHERE id_funcionario = :id 
                  AND id_cliente = :id_cliente";
    $stmtDelete = $conn->prepare($sqlDelete);
    $stmtDelete->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);

    // ============================================================
    // 8. REGISTRAR NO HISTÓRICO DO SISTEMA
    // ============================================================
    try {
        $sqlHistorico = "INSERT INTO historico_sistema (
            id_funcionario,
            tabela_afetada,
            id_registro_afetado,
            acao,
            dados_anteriores,
            ip_origem
        ) VALUES (
            :id_funcionario,
            'funcionarios',
            :id_registro,
            'DELETE',
            :dados,
            :ip
        )";
        $stmtHistorico = $conn->prepare($sqlHistorico);
        $stmtHistorico->execute([
            ':id_funcionario' => $id_usuario_logado,
            ':id_registro' => $id,
            ':dados' => json_encode([
                'profissional' => $usuario['nome_funcionario'],
                'email' => $usuario['email_funcionario'] ?? 'N/A',
                'cargo' => $usuario['cargo_funcionario'],
                'status' => $usuario['status_acesso']
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        // Não interrompe o processo se falhar o histórico
        error_log('Erro ao registrar exclusão: ' . $e->getMessage());
    }

    $conn->commit();

    setMessage('success', "Profissional \"" . htmlspecialchars($usuario['nome_funcionario']) . "\" excluído com sucesso!");

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Erro ao excluir profissional: ' . $e->getMessage());
}

// ============================================================
// 9. REDIRECIONAR PARA A LISTAGEM
// ============================================================

redirect('listar_usuarios.php');
exit;