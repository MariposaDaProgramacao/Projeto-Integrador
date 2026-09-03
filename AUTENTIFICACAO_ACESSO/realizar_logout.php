<?php
// ============================================================
// ARQUIVO: realizar_logout.php (MODIFICADO PARA MULTI-TENANT)
// ============================================================

// ============================================================
// 1. CARREGAR A CONEXÃO E CONFIGURAÇÕES
// ============================================================
require_once __DIR__ . '/../conexao_banco.php';

// ============================================================
// 2. VERIFICAR SE O USUÁRIO ESTÁ LOGADO E REGISTRAR SAÍDA
// ============================================================

// Verificar se há um usuário logado para registrar o logout
if (isLoggedIn()) {
    $id_usuario = getUsuarioId();
    $id_cliente = getClienteId();
    $nome_usuario = $_SESSION['nome_usuario'] ?? 'Desconhecido';
    
    try {
        // ============================================================
        // 2.1 REGISTRAR LOGOUT NO HISTÓRICO DO SISTEMA
        // ============================================================
        $sql_historico = "INSERT INTO historico_sistema 
                        (id_funcionario, tabela_afetada, id_registro_afetado, acao, dados_novos, ip_origem) 
                        VALUES 
                        (:id_funcionario, 'usuarios_sistema', :id_registro, 'logout', :dados, :ip)";
        $stmt_historico = $conn->prepare($sql_historico);
        $stmt_historico->execute([
            ':id_funcionario' => $id_usuario,
            ':id_registro' => $id_usuario,
            ':dados' => json_encode([
                'usuario' => $nome_usuario,
                'cliente_id' => $id_cliente,
                'data_saida' => date('Y-m-d H:i:s')
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        // ============================================================
        // 2.2 ATUALIZAR DATA DO ÚLTIMO ACESSO
        // ============================================================
        // (Opcional: já atualizamos no login, mas podemos manter)
        // $sql = "UPDATE usuarios_sistema SET data_ultimo_acesso = NOW() WHERE id_usuario = :id";
        // $stmt = $conn->prepare($sql);
        // $stmt->execute([':id' => $id_usuario]);
        
    } catch (PDOException $e) {
        // Não interrompe o logout se falhar ao registrar histórico
        error_log('Erro ao registrar logout: ' . $e->getMessage());
    }
}

// ============================================================
// 3. MENSAGEM DE SUCESSO (ANTES DE DESTRUIR A SESSÃO)
// ============================================================
// Salvar mensagem para exibir após o logout
setMessage('success', 'Você saiu do sistema com sucesso.');

// ============================================================
// 4. DESTRUIR A SESSÃO COMPLETAMENTE
// ============================================================

// Limpar todas as variáveis de sessão
$_SESSION = array();

// Destruir o cookie da sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destruir a sessão completamente
session_destroy();

// ============================================================
// 5. REDIRECIONAR PARA O LOGIN
// ============================================================

// OPÇÃO 1: Redirecionamento simples (se estiver na mesma pasta)
header('Location: realizar_login.php');

// OPÇÃO 2: Redirecionamento com caminho completo (DESCOMENTE ESTA)
// header('Location: /Gerenciador_Ambiente/SistemaGerenciamentoAmbientes/AUTENTIFICACAO_ACESSO/realizar_login.php');

// OPÇÃO 3: Redirecionamento com caminho relativo (se a OPÇÃO 2 não funcionar)
// header('Location: ../AUTENTIFICACAO_ACESSO/realizar_login.php');

exit;
?>