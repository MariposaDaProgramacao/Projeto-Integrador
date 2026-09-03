<?php
// ============================================================
// ARQUIVO: USUARIOS(ADM)/bloquear_usuario.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Bloquear usuário (ativo → bloqueado)
// ============================================================

// ============================================================
// 1. INICIAR SESSÃO E CARREGAR CONEXÃO
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR LOGIN (NOVO SISTEMA)
// ============================================================

if (!isLoggedIn()) {
    setMessage('error', 'Você precisa estar logado para acessar esta página.');
    redirect('../AUTENTIFICACAO_ACESSO/realizar_login.php');
}

// ============================================================
// 3. VERIFICAR PERMISSÃO (NOVO SISTEMA)
// ============================================================

$tipos_permitidos = ['admin_cliente', 'gerente'];
if (!in_array($_SESSION['tipo_usuario'] ?? '', $tipos_permitidos)) {
    setMessage('error', 'Acesso negado. Apenas administradores e coordenadores podem bloquear usuários.');
    redirect('../AUTENTIFICACAO_ACESSO/dashboard.php');
}

// ============================================================
// 4. VARIÁVEIS DO SISTEMA (NOVO)
// ============================================================

$id_cliente = getClienteId();
$id_usuario_logado = getUsuarioId();
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
// 5. RECEBER ID DO USUÁRIO
// ============================================================

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setMessage('error', 'ID do usuário inválido.');
    redirect('listar_usuarios.php');
}

// ============================================================
// 6. BUSCAR DADOS DO USUÁRIO (FILTRADO POR CLIENTE)
// ============================================================

try {
    $sql = "SELECT u.*, un.nome_unidade 
            FROM usuarios_sistema u
            LEFT JOIN unidades un ON u.id_unidade = un.id_unidade AND un.id_cliente = u.id_cliente
            WHERE u.id_usuario = :id 
            AND u.id_cliente = :id_cliente";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':id_cliente' => $id_cliente
    ]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        setMessage('error', 'Usuário não encontrado ou não pertence à sua organização.');
        redirect('listar_usuarios.php');
    }

    // Verificar permissão: gerente só pode bloquear usuários da sua unidade
    if ($tipo_usuario === 'gerente') {
        if ($usuario['id_unidade'] != $id_unidade_usuario) {
            setMessage('error', 'Você não tem permissão para bloquear este usuário.');
            redirect('listar_usuarios.php');
        }
    }

    // Verificar se já está bloqueado
    if ($usuario['status_usuario'] === 'bloqueado') {
        setMessage('warning', 'Este usuário já está bloqueado.');
        redirect('listar_usuarios.php');
    }

    if ($usuario['status_usuario'] === 'inativo') {
        setMessage('error', 'Usuários inativos não podem ser bloqueados. Aprove primeiro.');
        redirect('listar_usuarios.php');
    }

    // Não permitir bloquear administradores
    if ($usuario['tipo_usuario'] === 'admin_cliente') {
        setMessage('error', 'Não é possível bloquear um administrador.');
        redirect('listar_usuarios.php');
    }

    // Não permitir bloquear a si mesmo
    if ($usuario['id_usuario'] == $id_usuario_logado) {
        setMessage('error', 'Você não pode bloquear a si mesmo.');
        redirect('listar_usuarios.php');
    }

} catch (PDOException $e) {
    setMessage('error', 'Erro ao buscar usuário: ' . $e->getMessage());
    redirect('listar_usuarios.php');
}

// ============================================================
// 7. BLOQUEAR USUÁRIO
// ============================================================

try {
    $conn->beginTransaction();

    $sqlUpdate = "UPDATE usuarios_sistema 
                  SET status_usuario = 'bloqueado' 
                  WHERE id_usuario = :id 
                  AND id_cliente = :id_cliente";
    $stmtUpdate = $conn->prepare($sqlUpdate);
    $stmtUpdate->execute([
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
            dados_novos,
            ip_origem
        ) VALUES (
            :id_funcionario,
            'usuarios_sistema',
            :id_registro,
            'UPDATE',
            :dados,
            :ip
        )";
        $stmtHistorico = $conn->prepare($sqlHistorico);
        $stmtHistorico->execute([
            ':id_funcionario' => $id_usuario_logado,
            ':id_registro' => $id,
            ':dados' => json_encode([
                'usuario' => $usuario['nome_usuario'],
                'email' => $usuario['email_usuario'],
                'status_anterior' => $usuario['status_usuario'],
                'status_novo' => 'bloqueado',
                'acao' => 'Bloqueio de usuário'
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        // Não interrompe o processo se falhar o histórico
        error_log('Erro ao registrar bloqueio: ' . $e->getMessage());
    }

    $conn->commit();

    setMessage('success', 'Usuário <strong>' . htmlspecialchars($usuario['nome_usuario']) . '</strong> bloqueado com sucesso!');

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Erro ao bloquear usuário: ' . $e->getMessage());
}

// ============================================================
// 9. REDIRECIONAR PARA A LISTAGEM
// ============================================================

redirect('listar_usuarios.php');
exit;