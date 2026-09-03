<?php
// teste_sessao.php
session_start();

echo "<h1>Teste de Sessão</h1>";
echo "<pre>";
echo "Sessão atual:\n";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Variáveis individuais:</h2>";
echo "id_usuario: " . ($_SESSION['id_usuario'] ?? 'NÃO DEFINIDO') . "<br>";
echo "id_cliente: " . ($_SESSION['id_cliente'] ?? 'NÃO DEFINIDO') . "<br>";
echo "nome_usuario: " . ($_SESSION['nome_usuario'] ?? 'NÃO DEFINIDO') . "<br>";
echo "tipo_usuario: " . ($_SESSION['tipo_usuario'] ?? 'NÃO DEFINIDO') . "<br>";

echo "<h2>Botões:</h2>";
echo '<a href="AUTENTIFICACAO_ACESSO/realizar_login.php">Voltar ao Login</a><br>';
echo '<a href="AUTENTIFICACAO_ACESSO/dashboard.php">Ir para Dashboard</a><br>';
echo '<a href="AUTENTIFICACAO_ACESSO/realizar_logout.php">Sair</a>';
?>