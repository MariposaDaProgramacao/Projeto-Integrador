# 01. Página Inicial (index.php)

**Localização:** `index.php` (raiz do projeto)

**Função do arquivo:** Página inicial pública do sistema SIGA. Verifica se o usuário já está logado (redirecionando para o dashboard, se sim) e apresenta a landing page do projeto integrador com informações sobre o sistema, dados do curso, UCs, integrantes e link para login.

---

## Tabela de Funções

| Nº | Nome da função | Objetivo | Lógica | Regras |
|---|---|---|---|---|

| 1 | iniciar sessão | Garantir que uma sessão PHP esteja ativa | Verifica e inicia a sessão se necessário | Se: session_status() = PHP_SESSION_NONE → session_start(). Senão: manter. |

| 2 | verificação de sessão ativa | Redirecionar usuário logado para o dashboard | Checa $_SESSION['usuario_id'] | Se: existe → redirecionar dashboard.php e exit. Senão: continuar. |

| 3 | sidebar - logo e botão de login | Exibir barra lateral com logo e botão de login | Renderiza sidebar com logo SIGA | Se: página carregada → exibir sidebar. Senão: N/A. |

| 4 | container com imagem de fundo e conteúdo principal | Renderizar container principal com fundo e conteúdo | Exibe imagem Predio.png + overlay | Se: página carregada → exibir imagem + main. Senão: N/A. |

| 5 | hero - título e descrição do projeto | Exibir cabeçalho de destaque da página | Mostra selo + título + descrição | Se: página carregada → exibir hero. Senão: N/A. |

| 6 | seção "Sobre o Projeto" + cards | Apresentar descrição do sistema e funcionalidades | Exibe texto + 4 cards de features | Se: página carregada → exibir. Senão: N/A. |

| 7 | seção "Dados do Projeto" | Apresentar dados acadêmicos do projeto | Exibe Turma, Instituição e Docente | Se: página carregada → exibir 3 cards. Senão: N/A. |

| 8 | seção "Unidades Curriculares (UCs)" | Listar as 16 UCs do curso | Grade com 16 itens, destacando 04, 08, 16 | Se: página carregada → exibir grade. Senão: N/A. |

| 9 | seção "Integrantes do Projeto" | Listar integrantes com links do LinkedIn | 6 links + 1 placeholder (Carlos Wilson) | Se: página carregada → exibir. Senão: N/A. |

| 10 | footer - rodapé do cartão | Exibir rodapé com ano e nome do curso | Mostra 2026 — Técnico em Des. de Sistemas | Se: página carregada → exibir footer. Senão: N/A. |

---

## Observações

- **Tipo de arquivo:** híbrido (PHP + HTML + CSS inline)
- **Dependências:** nenhuma externa (sessão PHP nativa)
- **Acesso:** público (não requer login)
- **Última alteração:** 05/10/2026