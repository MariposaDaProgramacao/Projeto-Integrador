<?php
// ============================================================
// ARQUIVO: trocar_unidade.php
// FUNÇÃO: Resetar a unidade selecionada na sessão e redirecionar
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// VERIFICAR SE O USUÁRIO ESTÁ LOGADO
// ============================================================
if (!isset($_SESSION['usuario_id'])) {
    header('Location: AUTENTIFICACAO_ACESSO/realizar_login.php');
    exit;
}

// ============================================================
// VERIFICAR SE O USUÁRIO É ADMINISTRADOR
// ============================================================
if ($_SESSION['usuario_cargo'] !== 'administrador') {
    $_SESSION['erro'] = 'Acesso negado. Apenas administradores podem trocar de unidade.';
    header('Location: AUTENTIFICACAO_ACESSO/dashboard.php');
    exit;
}

// ============================================================
// RECEBER PARA ONDE REDIRECIONAR
// ============================================================
$redirect = $_GET['redirect'] ?? '';

// ============================================================
// REMOVER AS UNIDADES SELECIONADAS DA SESSÃO
// ============================================================
unset($_SESSION['unidade_selecionada_admin']);        // Para Mapa de Salas
unset($_SESSION['unidade_selecionada_admin_salas']);  // Para Listar Salas

// ============================================================
// DEFINIR PÁGINA DE REDIRECIONAMENTO
// ============================================================
if ($redirect === 'listar_salas.php') {
    header('Location: SALAS/listar_salas.php');
} elseif ($redirect === 'mapa_salas_dia.php') {
    header('Location: MAPA/mapa_salas_dia.php');  // ← CORRIGIDO: pasta MAPA
} elseif ($redirect === 'listar_aulas_dia.php') {
    header('Location: CRONOGRAMA_AULAS/listar_aulas_dia.php');
}elseif($redirect === 'mapa_sala.php'){
    header('Location: MAPA/mapa_sala.php');
} else {
    header('Location: AUTENTIFICACAO_ACESSO/dashboard.php');
}
exit;
?>