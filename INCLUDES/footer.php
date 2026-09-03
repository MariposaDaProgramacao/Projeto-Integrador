<?php
// ============================================================
// ARQUIVO: INCLUDES/footer.php (MODIFICADO PARA MULTI-TENANT)
// FUNÇÃO: Rodapé padrão do sistema com CSS e JS embutidos
// ============================================================
?>
<style>
/* ======================================================
   RESET & BASE
   ====================================================== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: #f0f4fb;
    display: flex;
    height: 100vh;
    overflow: hidden;
}

/* ======================================================
   SIDEBAR
   ====================================================== */
.sidebar {
    width: 270px;
    background: #ffffff;
    border-right: 1px solid #e8edf5;
    padding: 28px 20px;
    display: flex;
    flex-direction: column;
    gap: 28px;
    flex-shrink: 0;
    overflow-y: auto;
    height: 100vh;
}

.logo-area {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f0f4fb;
}

.logo-icon {
    background: linear-gradient(145deg, #1a73e8, #0d47a1);
    width: 44px;
    height: 44px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    box-shadow: 0 8px 16px -6px rgba(26, 115, 232, 0.3);
}

.logo-text {
    font-size: 20px;
    font-weight: 700;
    color: #1a2639;
}
.logo-text span {
    color: #1a73e8;
}
.logo-text small {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: #7a8aa0;
    margin-top: 2px;
}

.sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
}

.menu-label {
    font-size: 11px;
    font-weight: 600;
    color: #9aabbf;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 16px 6px;
}

.menu-item {
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

.menu-item i {
    width: 20px;
    font-size: 16px;
    color: #8a9bb5;
    transition: color 0.15s;
}

.menu-item:hover {
    background: #f0f6ff;
    color: #1a2639;
}
.menu-item:hover i {
    color: #1a73e8;
}

.menu-item.active {
    background: #1a73e8;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
}
.menu-item.active i {
    color: #ffffff;
}

.menu-item .badge-menu {
    margin-left: auto;
    background: #ff6b6b;
    color: #fff;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 60px;
    font-weight: 600;
}

.sidebar-footer {
    border-top: 1px solid #edf2f9;
    padding-top: 16px;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
    margin-top: auto;
}

.sidebar-footer .user-row {
    display: flex;
    align-items: center;
    gap: 14px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(145deg, #eef2f9, #dce3ef);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
    color: #2d3a4f;
}

.user-info {
    line-height: 1.3;
}
.user-info .name {
    font-weight: 600;
    font-size: 13px;
    color: #1a2639;
}
.user-info .role {
    font-size: 12px;
    color: #8a9bb5;
}
.user-info .cliente {
    font-size: 11px;
    color: #1a73e8;
    font-weight: 500;
    display: block;
    margin-top: 2px;
}

.status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #34a853;
    margin-right: 6px;
}

.logout-btn-sidebar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #dc3545;
    color: #ffffff;
    border: none;
    border-radius: 60px;
    padding: 10px 16px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.2s ease;
    width: 100%;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
    cursor: pointer;
}

.logout-btn-sidebar:hover {
    background: #c82333;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
}

/* ======================================================
   MAIN CONTENT
   ====================================================== */
.main {
    flex: 1;
    padding: 28px 36px 20px;
    overflow-y: auto;
    background: #f0f4fb;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

/* ======================================================
   CONTAINER COM IMAGEM DE FUNDO
   ====================================================== */
.bg-container {
    position: relative;
    flex: 1;
    overflow: hidden;
    background: #f0f4fb; /* fallback */
}

.bg-image-cover {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 0;
}

/* Main dentro do bg-container: fica sobre a imagem */
.bg-container .main {
    position: relative;
    z-index: 1;
    background: transparent; /* tira o fundo para mostrar a imagem */
    padding: 40px 36px 80px;
    height: 100%;
    overflow-y: auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ======================================================
   CARTÃO "SOBRE O PROJETO"
   ====================================================== */
.about-card {
    max-width: 700px;
    width: 100%;
    margin: 0 auto;
    padding: 32px 36px;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(2px);
    border-radius: 24px;
    box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.5);
    transition: transform 0.2s ease;
}

.about-card:hover {
    transform: scale(1.01);
}

.card-header {
    margin-bottom: 20px;
    border-bottom: 2px solid #eef3fa;
    padding-bottom: 12px;
}

.card-header h3 {
    font-size: 18px;
    color: #0e1a2b;
}

.card-header h3 i {
    color: #1a73e8;
    margin-right: 10px;
}

.card-content-center {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.card-title {
    font-size: 26px;
    font-weight: 700;
    color: #0e1a2b;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.card-description {
    font-size: 15px;
    color: #4a5a72;
    max-width: 500px;
    margin: 0 auto 20px auto;
    line-height: 1.6;
}

.divider-line {
    width: 80px;
    height: 3px;
    background: linear-gradient(90deg, #1a73e8, #6fa3ff);
    border-radius: 10px;
    margin: 16px auto 24px auto;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 20px;
    background: #f8faff;
    padding: 16px 24px;
    border-radius: 16px;
    width: 100%;
    max-width: 450px;
    margin: 0 auto 20px auto;
    text-align: left;
    font-size: 14px;
    color: #1a2639;
}

.info-grid .full-width {
    grid-column: 1 / -1;
    text-align: center;
    font-weight: 600;
    color: #0e1a2b;
    padding-bottom: 6px;
    border-bottom: 1px dashed #dce3ef;
}

.info-grid .full-width.title {
    font-size: 15px;
    border-bottom: none;
    padding-bottom: 0;
}

.info-grid .full-width.divider {
    border-top: 1px solid #e2e9f3;
    padding-top: 10px;
    margin-top: 4px;
}

.info-grid i {
    color: #1a73e8;
    width: 22px;
    margin-right: 6px;
}

.members-section {
    width: 100%;
    max-width: 450px;
    margin: 10px auto 16px auto;
}

.members-title {
    font-weight: 600;
    font-size: 15px;
    color: #0e1a2b;
    margin-bottom: 10px;
}

.members-title i {
    color: #1a73e8;
    margin-right: 8px;
}

.members-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 20px;
    background: #f8faff;
    padding: 12px 20px;
    border-radius: 16px;
}

.members-grid span,
.member-link {
    font-size: 14px;
    color: #1a2639;
    background: #ffffff;
    padding: 4px 12px;
    border-radius: 60px;
    border: 1px solid #eef3fa;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    text-decoration: none !important;
    display: inline-block;
    transition: background 0.2s, transform 0.15s, border-color 0.2s;
    cursor: pointer;
}

.member-link:hover {
    background: #f0f6ff;
    transform: scale(1.04);
    border-color: #1a73e8;
    box-shadow: 0 4px 8px rgba(26, 115, 232, 0.15);
}

.project-year {
    font-size: 14px;
    color: #5a6a7e;
    margin-top: 10px;
    font-weight: 500;
}

.project-year i {
    color: #1a73e8;
    margin-right: 6px;
}

/* ======================================================
   PAGE HEADER
   ====================================================== */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    font-size: 24px;
    font-weight: 700;
    color: #0e1a2b;
    margin-bottom: 6px;
}

.page-title i {
    color: #1a73e8;
    margin-right: 10px;
}

.page-subtitle {
    font-size: 14px;
    color: #7a8aa0;
    margin-bottom: 0;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* ======================================================
   BOTÕES
   ====================================================== */
.btn {
    padding: 9px 20px;
    border-radius: 60px;
    border: none;
    font-weight: 600;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.2s;
    background: #ffffff;
    color: #1a2639;
    border: 1px solid #e2e9f3;
    text-decoration: none;
}

.btn-primary {
    background: #1a73e8;
    color: #ffffff;
    border: none;
    box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
}
.btn-primary:hover {
    background: #1557b0;
    transform: scale(1.02);
}

.btn-success {
    background: #34a853;
    color: #ffffff;
    border: none;
}
.btn-success:hover {
    background: #2d9248;
}

.btn-danger {
    background: #dc3545;
    color: #ffffff;
    border: none;
}
.btn-danger:hover {
    background: #c82333;
}

.btn-warning {
    background: #ffc107;
    color: #1a2639;
    border: none;
}
.btn-warning:hover {
    background: #e0a800;
}

.btn-outline {
    background: transparent;
    border: 1px solid #d8e0ec;
}
.btn-outline:hover {
    background: #f0f4fb;
}

.btn-redefinir-senha {
    background: #e67e22;
    color: #fff;
    border-color: #d35400;
    font-weight: 600;
    transition: all 0.2s;
}
.btn-redefinir-senha:hover {
    background: #d35400;
    border-color: #a04000;
    color: #fff;
}

/* ======================================================
   CARD PANEL (genérico)
   ====================================================== */
.card-panel {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #ebf0f8;
    padding: 24px 28px;
    margin-bottom: 20px;
}

/* ======================================================
   USER HEADER (nome em destaque - usado em editar_usuario)
   ====================================================== */
.user-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
    border-bottom: 2px solid #edf2f9;
    padding-bottom: 16px;
    flex-wrap: wrap;
}

.user-header .user-icon {
    font-size: 48px;
    color: #0e1a2b;
    background: #f0f4fb;
    padding: 8px;
    border-radius: 50%;
}

.user-header .user-name {
    margin: 0;
    font-size: 24px;
    color: #0e1a2b;
    font-weight: 700;
    flex: 1;
}

.user-header .user-details {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 4px;
    width: 100%;
}

.user-header .user-details span {
    font-size: 14px;
    color: #5a6a7e;
}

.user-header .user-details i {
    margin-right: 4px;
}

.user-header .user-status {
    text-align: right;
    margin-left: auto;
}

/* ======================================================
   USER INFO GRID (cargo e unidade)
   ====================================================== */
.user-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}

.user-info-grid .info-item {
    display: flex;
    flex-direction: column;
}

.user-info-grid .info-item .label {
    font-size: 12px;
    color: #7a8aa0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.user-info-grid .info-item .value {
    font-weight: 600;
    font-size: 16px;
}

/* ======================================================
   ACTION BUTTONS (aprovar, bloquear, redefinir, excluir)
   ====================================================== */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-top: 1px solid #edf2f9;
    padding-top: 16px;
    margin-top: 16px;
}

/* ======================================================
   FORMULÁRIO
   ====================================================== */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #1a2639;
    margin-bottom: 5px;
}

.form-group label i {
    color: #1a73e8;
    margin-right: 6px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 16px;
    border-radius: 12px;
    border: 1px solid #e2e9f3;
    background: #fafcff;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #1a2639;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #1a73e8;
    box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
}

.form-group select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a8aa0' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
    cursor: pointer;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid #f0f4fb;
    flex-wrap: wrap;
}

.form-actions .btn {
    flex: 0 1 auto;
    justify-content: center;
    padding: 10px 28px;
    font-size: 14px;
}

/* ======================================================
   BADGES
   ====================================================== */
.badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 60px;
    font-size: 12px;
    font-weight: 600;
}

.badge-success {
    background: #e6f7e9;
    color: #1e8546;
}
.badge-warning {
    background: #fff2e0;
    color: #b86a1f;
}
.badge-danger {
    background: #ffe9e9;
    color: #b33a3a;
}
.badge-info {
    background: #e3f2fd;
    color: #0d47a1;
}
.badge-purple {
    background: #f3e5f5;
    color: #6a1b9a;
}
.badge-orange {
    background: #fff3e0;
    color: #e37400;
}

/* ======================================================
   ALERTAS
   ====================================================== */
.alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-danger {
    background: #ffe9e9;
    color: #b33a3a;
    border: 1px solid #ffd6d6;
}

.alert-success {
    background: #e6f7e9;
    color: #1e8546;
    border: 1px solid #c8f0cf;
}

.alert i {
    font-size: 18px;
}

/* ======================================================
   RESPONSIVIDADE
   ====================================================== */
@media (max-width: 820px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: -300px;
        width: 280px;
        height: 100vh;
        z-index: 999;
        transition: left 0.3s ease;
        padding-top: 70px;
    }
    .sidebar.open {
        left: 0;
    }
    .main {
        padding: 16px 18px;
    }
    .bg-container .main {
        padding: 20px 16px 80px;
    }
    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .user-header .user-status {
        width: 100%;
        text-align: left;
        margin-top: 8px;
    }
    .user-info-grid {
        grid-template-columns: 1fr;
    }
    .about-card {
        padding: 24px 18px;
    }
    .card-title {
        font-size: 20px;
    }
    .info-grid {
        grid-template-columns: 1fr;
        text-align: center;
        padding: 12px 16px;
    }
    .info-grid .full-width {
        grid-column: 1;
    }
    .members-grid {
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }
    .members-grid span,
    .member-link {
        width: 100%;
        text-align: center;
    }
}

@media (max-width: 540px) {
    .main {
        padding: 12px 14px;
    }
    .bg-container .main {
        padding: 12px 14px 80px;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .header-actions {
        width: 100%;
        flex-wrap: wrap;
    }
    .header-actions .btn {
        flex: 1;
        justify-content: center;
        font-size: 12px;
        padding: 8px 12px;
    }
    .card-panel {
        padding: 18px 16px;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    .action-buttons .btn {
        flex: 1;
        justify-content: center;
    }
    .about-card {
        padding: 20px 16px;
    }
}

/* ======================================================
   LOGIN - ESTILOS ESPECÍFICOS
   ====================================================== */

/* Garantia de que o body ocupe 100% da tela */
body.login-page {
    margin: 0;
    padding: 0;
    font-family: 'Inter', sans-serif;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f0f4fb;
    overflow: hidden;
}

/* Imagem de fundo ocupa toda a tela */
.bg-image {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 0;
    overflow: hidden;
}

.bg-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Wrapper centraliza o card com overlay */
.login-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.35); /* overlay escuro */
    padding: 20px;
    box-sizing: border-box;
}

/* Card de login */
.login-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    padding: 40px 36px;
    border-radius: 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 420px;
    border: 1px solid rgba(255, 255, 255, 0.6);
    transition: transform 0.2s ease;
}

.login-card:hover {
    transform: translateY(-2px);
}

/* Ícone do login */
.login-icon {
    font-size: 48px;
    color: #1a73e8;
    text-align: center;
    margin-bottom: 8px;
}

/* Títulos */
.login-card h2 {
    font-size: 26px;
    font-weight: 700;
    color: #0e1a2b;
    margin: 6px 0 2px;
    text-align: center;
}

.login-card .subtitle {
    text-align: center;
    color: #7a8aa0;
    font-size: 14px;
    margin-bottom: 24px;
}

/* Grupos de formulário */
.login-card .form-group {
    margin-bottom: 18px;
}

.login-card .form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #1a2639;
    margin-bottom: 5px;
}

.login-card .form-group input {
    width: 100%;
    padding: 10px 16px;
    border-radius: 12px;
    border: 1px solid #e2e9f3;
    background: #fafcff;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #1a2639;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
    box-sizing: border-box;
}

.login-card .form-group input:focus {
    border-color: #1a73e8;
    box-shadow: 0 0 0 4px rgba(26, 115, 232, 0.1);
}

.login-card .form-group input::placeholder {
    color: #9aabbf;
}

/* Botão de login */
.login-card .btn {
    width: 100%;
    justify-content: center;
    padding: 12px;
    font-size: 15px;
    border-radius: 60px;
    margin-top: 6px;
    border: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.login-card .btn-primary {
    background: #1a73e8;
    color: #ffffff;
    box-shadow: 0 6px 16px -4px rgba(26, 115, 232, 0.35);
}

.login-card .btn-primary:hover {
    background: #1557b0;
    transform: scale(1.02);
}

/* Alertas (mensagens de erro/sucesso) */
.login-card .alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.login-card .alert-danger {
    background: #ffe9e9;
    color: #b33a3a;
    border: 1px solid #ffd6d6;
}

.login-card .alert-success {
    background: #e6f7e9;
    color: #1e8546;
    border: 1px solid #c8f0cf;
}

.login-card .alert i {
    font-size: 18px;
}

/* Rodapé */
.login-card .footer-text {
    text-align: center;
    margin-top: 20px;
    font-size: 12px;
    color: #8a9bb5;
}

.login-card .footer-text i {
    margin-right: 4px;
}

/* Responsividade */
@media (max-width: 480px) {
    .login-card {
        padding: 28px 20px;
    }
    .login-card h2 {
        font-size: 22px;
    }
    .login-card .btn {
        font-size: 14px;
        padding: 10px;
    }
}

/* ======================================================
   RODAPÉ
   ====================================================== */
.footer-system {
    text-align: center;
    font-size: 12px;
    color: #8a9bb5;
    padding: 16px 0 8px;
    border-top: 1px solid #e2e9f3;
    margin-top: auto;
    background: transparent;
    flex-shrink: 0;
}

.footer-system .footer-content i {
    color: #1a73e8;
    margin-right: 4px;
}

.footer-system .footer-divider {
    margin: 0 8px;
    color: #dce3ef;
}

.footer-system .footer-version {
    color: #aab8cc;
    font-weight: 400;
}

/* Menu toggle (hamburger) */
.menu-toggle {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    z-index: 1000;
    background: #1a73e8;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 22px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
    transition: background 0.2s;
}

.menu-toggle:hover {
    background: #1557b0;
}

.menu-toggle i {
    font-size: 24px;
}

.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.4);
    z-index: 998;
    opacity: 0;
    transition: opacity 0.3s;
}

.sidebar-overlay.active {
    display: block;
    opacity: 1;
}

body.menu-open {
    overflow: hidden;
}

/* Ajustes para telas pequenas */
@media (max-width: 820px) {
    .menu-toggle {
        display: block;
    }
}
</style>

<!-- ========================================== -->
<!-- RODAPÉ PADRÃO DO SISTEMA                   -->
<!-- ========================================== -->
<footer class="footer-system">
    <div class="footer-content">
        <i class="fas fa-shield-alt"></i> 
        Sistema Gerenciador de Salas - Gerenciamento de Ambientes
        <span class="footer-divider">|</span>
        <span class="footer-version">v2.0</span>
        <span class="footer-divider">|</span>
        <span style="font-size: 11px; color: #9aabbf;">
            <i class="fas fa-users"></i> Multi-Tenant
        </span>
    </div>
</footer>

<script>
    (function() {
        'use strict';

        // Elementos
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const body = document.body;

        // Verifica se os elementos existem antes de adicionar eventos
        if (!menuToggle || !sidebar || !overlay) {
            return;
        }

        // Função para abrir/fechar menu
        function toggleMenu() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            body.classList.toggle('menu-open');
            // Alterna o ícone
            const icon = menuToggle.querySelector('i');
            if (sidebar.classList.contains('open')) {
                icon.className = 'fas fa-times';
                menuToggle.style.background = '#dc3545';
            } else {
                icon.className = 'fas fa-bars';
                menuToggle.style.background = '#1a73e8';
            }
        }

        // Evento de clique no botão
        menuToggle.addEventListener('click', toggleMenu);

        // Evento de clique no overlay (fecha o menu)
        overlay.addEventListener('click', function() {
            if (sidebar.classList.contains('open')) {
                toggleMenu();
            }
        });

        // Fecha o menu ao redimensionar para desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 820 && sidebar.classList.contains('open')) {
                toggleMenu();
            }
        });

        // Fecha o menu ao pressionar ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                toggleMenu();
            }
        });

    })();
</script>