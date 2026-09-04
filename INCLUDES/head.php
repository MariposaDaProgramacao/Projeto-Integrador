<?php
// ============================================================
// ARQUIVO: INCLUDES/head.php
// FUNÇÃO: Cabeçalho HTML compartilhado do sistema
// IMPORTANTE: este arquivo NÃO executa login nem redirecionamentos.
// A autenticação deve ser validada pela página antes deste include.
// ============================================================

if (!isset($titulo) || trim((string)$titulo) === '') {
    $titulo = 'Gerenciador de Salas';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../APARENCIA/estilo.css">

    <style>
        /* Compatibilidade com a classe usada pelo INCLUDES/sidebar.php */
        .menu-item-link {
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
        .menu-item-link i {
            width: 20px;
            font-size: 16px;
            color: #8a9bb5;
            transition: color 0.15s;
        }
        .menu-item-link:hover {
            background: #f0f6ff;
            color: #1a2639;
        }
        .menu-item-link:hover i { color: #1a73e8; }
        .menu-item-link.active {
            background: #1a73e8;
            color: #fff;
            box-shadow: 0 4px 12px rgba(26,115,232,.3);
        }
        .menu-item-link.active i { color: #fff; }
        .menu-item-link .badge-menu {
            margin-left: auto;
            background: #ff6b6b;
            color: #fff;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 60px;
            font-weight: 600;
        }
    </style>
</head>
<body>
