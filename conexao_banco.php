<?php
// ============================================================
// ARQUIVO: conexao_banco.php (RAIZ) - MODIFICADO PARA MULTI-TENANT
// FUNÇÃO: Conexão com o banco de dados e funções auxiliares
// ============================================================

// ============================================================
// 1. CONFIGURAÇÕES DO BANCO DE DADOS
// ============================================================

$host = 'localhost';
$dbname = 'sistemagerenciamentoambientes';
$username = 'root';
$password = '';

// ============================================================
// 2. CONEXÃO PDO
// ============================================================

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Para compatibilidade com código antigo que usa $pdo
    $pdo = $conn;
    
} catch (PDOException $e) {
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}

// ============================================================
// 3. INICIAR SESSÃO (SE NÃO INICIADA)
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 4. FUNÇÕES AUXILIARES PARA MULTI-TENANT
// ============================================================

/**
 * Verifica se o usuário está logado
 * 
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
}

/**
 * Retorna o ID do cliente atual (da sessão)
 * 
 * @return int|null
 */
function getClienteId() {
    return $_SESSION['id_cliente'] ?? null;
}

/**
 * Retorna o ID do usuário logado
 * 
 * @return int|null
 */
function getUsuarioId() {
    return $_SESSION['usuario_id'] ?? null;
}

/**
 * Retorna o tipo do usuário logado
 * 
 * @return string|null
 */
function getTipoUsuario() {
    return $_SESSION['tipo_usuario'] ?? null;
}

/**
 * Retorna o nome do cliente atual
 * 
 * @return string|null
 */
function getNomeCliente() {
    return $_SESSION['nome_cliente'] ?? null;
}

/**
 * Define uma mensagem na sessão
 * 
 * @param string $tipo 'success' ou 'error'
 * @param string $mensagem
 * @return void
 */
function setMessage($tipo, $mensagem) {
    $_SESSION['mensagem'] = [
        'tipo' => $tipo,
        'mensagem' => $mensagem
    ];
}

/**
 * Recupera e remove a mensagem da sessão
 * 
 * @return array|null
 */
function getMessage() {
    if (isset($_SESSION['mensagem'])) {
        $mensagem = $_SESSION['mensagem'];
        unset($_SESSION['mensagem']);
        return $mensagem;
    }
    return null;
}

/**
 * Redireciona para uma URL
 * 
 * @param string $url
 * @return void
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Verifica se o usuário tem permissão para acessar determinada funcionalidade
 * 
 * @param array $tipos_permitidos Array de tipos de usuário permitidos
 * @return bool
 */
function hasPermission($tipos_permitidos = []) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $tipo = getTipoUsuario();
    if (empty($tipos_permitidos)) {
        return true;
    }
    
    return in_array($tipo, $tipos_permitidos);
}

/**
 * Verifica se o usuário tem permissão e redireciona se não tiver
 * 
 * @param array $tipos_permitidos
 * @param string $url_redirect URL para redirecionar se não tiver permissão
 * @return void
 */
function requirePermission($tipos_permitidos = [], $url_redirect = '../AUTENTIFICACAO_ACESSO/dashboard.php') {
    if (!hasPermission($tipos_permitidos)) {
        setMessage('error', 'Acesso negado. Você não tem permissão para acessar esta página.');
        redirect($url_redirect);
    }
}

/**
 * Busca o nome de uma unidade pelo ID (com validação de cliente)
 * 
 * @param PDO $conn Conexão com o banco
 * @param int $id_unidade ID da unidade
 * @param int $id_cliente ID do cliente
 * @return string Nome da unidade ou 'Unidade não definida'
 */
function buscarNomeUnidade($conn, $id_unidade, $id_cliente) {
    try {
        $stmt = $conn->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = :id AND id_cliente = :id_cliente");
        $stmt->execute([
            ':id' => $id_unidade,
            ':id_cliente' => $id_cliente
        ]);
        $nome = $stmt->fetchColumn();
        return $nome ?: 'Unidade não definida';
    } catch (PDOException $e) {
        return 'Unidade não definida';
    }
}

/**
 * Gera uma senha provisória
 * 
 * @param int $tamanho Tamanho da senha (padrão: 8)
 * @return string
 */
function gerarSenhaProvisoria($tamanho = 8) {
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $senha = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $senha .= $caracteres[random_int(0, strlen($caracteres) - 1)];
    }
    return $senha;
}

/**
 * Formata um telefone para exibição
 * 
 * @param string $telefone
 * @return string
 */
function formatarTelefone($telefone) {
    if (empty($telefone)) return '';
    $telefone = preg_replace('/\D/', '', $telefone);
    if (strlen($telefone) === 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 5) . '-' . substr($telefone, 7);
    } elseif (strlen($telefone) === 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 4) . '-' . substr($telefone, 6);
    }
    return $telefone;
}

/**
 * Limpa um telefone (remove caracteres não numéricos)
 * 
 * @param string $telefone
 * @return string
 */
function limparTelefone($telefone) {
    return preg_replace('/\D/', '', $telefone);
}

/**
 * Mapeia cargo para tipo de usuário no sistema
 * 
 * @param string $cargo Cargo do funcionário (professor, coordenador, etc.)
 * @return string Tipo de usuário no sistema
 */
function mapearCargoParaTipo($cargo) {
    $map = [
        'admin_cliente' => 'admin_cliente',
        'administrador' => 'admin_cliente',
        'coordenador' => 'gerente',
        'gerente' => 'gerente',
        'professor' => 'usuario',
        'auxiliar' => 'usuario',
        'secretaria' => 'usuario',
        'portaria' => 'usuario'
    ];
    return $map[$cargo] ?? 'usuario';
}

/**
 * Mapeia tipo de usuário para label amigável
 * 
 * @param string $tipo Tipo de usuário
 * @return string Label amigável
 */
function getTipoLabel($tipo) {
    $map = [
        'admin_cliente' => 'Administrador',
        'gerente' => 'Coordenador',
        'usuario' => 'Professor',
        'secretaria' => 'Secretaria',
        'portaria' => 'Portaria'
    ];
    return $map[$tipo] ?? ucfirst($tipo);
}

/**
 * Mapeia tipo de usuário para classe CSS do badge
 * 
 * @param string $tipo Tipo de usuário
 * @return string Classe CSS
 */
function getBadgeClass($tipo) {
    $map = [
        'admin_cliente' => 'badge-danger',
        'gerente' => 'badge-orange',
        'usuario' => 'badge-purple',
        'secretaria' => 'badge-info',
        'portaria' => 'badge-info'
    ];
    return $map[$tipo] ?? 'badge-info';
}

// ============================================================
// 5. VALIDAÇÃO DA SESSÃO (OPCIONAL - PODE SER CHAMADA NAS PÁGINAS)
// ============================================================

/**
 * Verifica se a sessão atual é válida e se o usuário ainda existe no banco
 * 
 * @param PDO $conn Conexão com o banco
 * @return bool
 */
function validarSessao($conn) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $id_usuario = getUsuarioId();
    $id_cliente = getClienteId();
    
    if (!$id_usuario || !$id_cliente) {
        return false;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT id_usuario, status_usuario 
            FROM usuarios_sistema 
            WHERE id_usuario = :id 
            AND id_cliente = :id_cliente
        ");
        $stmt->execute([
            ':id' => $id_usuario,
            ':id_cliente' => $id_cliente
        ]);
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            return false;
        }
        
        // Verificar se o usuário está ativo
        if ($usuario['status_usuario'] !== 'ativo') {
            return false;
        }
        
        return true;
        
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// 6. FUNÇÃO PARA REGISTRAR LOG NO SISTEMA
// ============================================================

/**
 * Registra uma ação no histórico do sistema
 * 
 * @param PDO $conn Conexão com o banco
 * @param int $id_funcionario ID do funcionário que realizou a ação
 * @param string $tabela Nome da tabela afetada
 * @param int $id_registro ID do registro afetado
 * @param string $acao Tipo de ação (INSERT, UPDATE, DELETE)
 * @param array|null $dados_antigos Dados antes da alteração (opcional)
 * @param array|null $dados_novos Dados depois da alteração (opcional)
 * @param string|null $motivo Motivo da ação (opcional)
 * @return bool
 */
function registrarHistorico($conn, $id_funcionario, $tabela, $id_registro, $acao, $dados_antigos = null, $dados_novos = null, $motivo = null) {
    try {
        $sql = "INSERT INTO historico_sistema (
            id_funcionario,
            tabela_afetada,
            id_registro_afetado,
            acao,
            dados_antigos,
            dados_novos,
            motivo,
            ip_origem,
            data_hora
        ) VALUES (
            :id_funcionario,
            :tabela,
            :id_registro,
            :acao,
            :dados_antigos,
            :dados_novos,
            :motivo,
            :ip,
            NOW()
        )";
        
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
            ':id_funcionario' => $id_funcionario,
            ':tabela' => $tabela,
            ':id_registro' => $id_registro,
            ':acao' => $acao,
            ':dados_antigos' => $dados_antigos ? json_encode($dados_antigos) : null,
            ':dados_novos' => $dados_novos ? json_encode($dados_novos) : null,
            ':motivo' => $motivo,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar histórico: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// 7. COMPATIBILIDADE COM CÓDIGO LEGADO
// ============================================================

// Para garantir compatibilidade com código que usa $pdo em vez de $conn
if (!isset($pdo)) {
    $pdo = $conn;
}

// Para garantir compatibilidade com código que usa $_SESSION['usuario_id'] ou $_SESSION['funcionario_id']
if (isset($_SESSION['usuario_id']) && !isset($_SESSION['funcionario_id'])) {
    $_SESSION['funcionario_id'] = $_SESSION['usuario_id'];
}
if (isset($_SESSION['funcionario_id']) && !isset($_SESSION['usuario_id'])) {
    $_SESSION['usuario_id'] = $_SESSION['funcionario_id'];
}

// Para garantir compatibilidade com $_SESSION['usuario_cargo']
if (isset($_SESSION['tipo_usuario']) && !isset($_SESSION['usuario_cargo'])) {
    $map_cargo = [
        'admin_cliente' => 'administrador',
        'gerente' => 'coordenador',
        'usuario' => 'professor'
    ];
    $_SESSION['usuario_cargo'] = $map_cargo[$_SESSION['tipo_usuario']] ?? $_SESSION['tipo_usuario'];
}

// Para garantir compatibilidade com $_SESSION['usuario_unidade']
if (isset($_SESSION['id_unidade']) && !isset($_SESSION['usuario_unidade'])) {
    $_SESSION['usuario_unidade'] = $_SESSION['id_unidade'];
}

?>