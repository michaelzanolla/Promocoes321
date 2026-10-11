# Progresso do projeto

Atualizado em 2026-10-11. Para retomar, leia este arquivo, o [README](../README.md) e o [plano da Fase 1](fase-1-planejamento.md).

## Onde paramos

**A Fase 7 está concluída e commitada** na branch `fase-7-comprovantes`.

Próximo passo ao retomar:

1. Conferir o visual do PDF do comprovante (pendência da Fase 7).
2. Iniciar a **Fase 8 — Preparação para produção** (escopo abaixo).

## Situação das fases

| Fase | Situação | Branch / commit |
|---|---|---|
| 1 — Análise e planejamento | Concluída | `fase-1-planejamento` · `a5e780c` |
| 2 — Ambiente, banco e autenticação | Concluída | `fase-2-base` · `e5c6744` |
| 3 — Sistema de design e páginas públicas | Concluída | `fase-3-design` · `3c0086f` |
| 4 — Painel administrativo (Filament) | Concluída | `fase-4-admin` · `d0c6fb3` |
| 5 — Seleção de números, reserva e checkout | Concluída | `fase-5-checkout` · `72ed127` |
| 6 — Pagamentos (Asaas: Pix e cartão) | Concluída | `fase-6-pagamentos` · `666eca8` |
| 7 — Comprovantes, área do participante, e-mails | Concluída | `fase-7-comprovantes` (último commit) |
| 8 — Preparação para produção | **Não iniciada** | — |

Cada branch foi criada a partir da anterior. A `main` ainda não tem commits; as fases ficam empilhadas até decidirmos como integrar.

Situação dos testes ao final da Fase 7: 144 testes passando, Pint e PHPStan sem erros.

## Pendências das fases concluídas

### Bloqueantes para publicar (não são código)

- [ ] **Validação jurídica** do modelo de promoção (rifa/ação entre amigos, autorização, publicidade). Em produção, o sistema exige a "referência legal" para publicar.
- [ ] **Confirmar por escrito com o Asaas** que a atividade (rifa/ação entre amigos) é aceita na conta. A pergunta já feita confirmou as formas de pagamento, não a atividade.
- [ ] **Textos legais definitivos** (termos, privacidade, cancelamento e reembolso, regras): hoje são provisórios, marcados como "pendente de revisão jurídica", em `resources/content/*.md`.
- [ ] **Dados do responsável e contato** reais em Painel > Configurações.

### Identidade e conteúdo

- [ ] Nome e marca definitivos: "Promoções 321" e o logotipo de bilhete são provisórios (`APP_NAME`, `resources/views/components/app-logo-icon.blade.php`).
- [ ] Os e-mails usam o visual padrão do Laravel, sem a identidade da plataforma.

### Fase 4 — Painel

- [ ] O painel (Filament) carrega a fonte padrão dele de um serviço externo (Bunny Fonts). O site público não faz isso. Dá para trocar por fonte local, se quiser zero chamadas externas.

### Fase 5 — Reserva e checkout

- [ ] `composer test` falha no `ConcurrencyTest` **só neste ambiente** (limite de ~34 processos por comando). Rodar `php artisan test` separado do Pint/PHPStan. Na máquina do usuário deve funcionar.
- [ ] Grade de números: navegação por setas do teclado (hoje só Tab, longo em grades de 500; a busca ajuda).
- [ ] Não há limite de pedidos abertos por pessoa: alguém poderia tentar segurar muitos números. Há limite por pedido e de 10 reservas por minuto.
- [ ] Não dá para trocar a forma de pagamento depois de confirmar os dados (é preciso desistir do pedido e reservar de novo).

### Fase 6 — Pagamentos

- [ ] **Webhook do Asaas não cadastrado**: precisa da URL pública (Fase 8). `ASAAS_WEBHOOK_TOKEN` está vazio no `.env` local. Com o token vazio, o sistema recusa todos os avisos, que é o comportamento seguro.
- [ ] **Reembolso e cartão** só testados com o Asaas simulado; testar no sandbox real (cartão de teste do Asaas na fatura).
- [ ] O cancelamento da cobrança Pix no Asaas, quando a reserva expira sozinha, acontece pela conciliação (até ~1 minuto depois). Pagamento nesse intervalo cai no fluxo de pagamento tardio, que está tratado.

### Fase 7 — Comprovantes e e-mails

- [ ] Abrir um PDF de comprovante e conferir o visual (não foi possível renderizar como imagem no ambiente de desenvolvimento). Ex.: pedido de teste `V8STAKRACZ` > Baixar PDF.
- [ ] SMTP de produção não configurado (Fase 8). Em desenvolvimento os e-mails vão para `storage/logs/laravel.log`.

## Escopo da Fase 8 — Preparação para produção

Hospedagem: HostGator plano M (cPanel), PHP 8.3, MySQL 8.0, subdomínio no mesmo cPanel do WordPress.

1. **Acesso**: cadastrar chave SSH no cPanel e testar o acesso.
2. **Subdomínio** com raiz em `promocoes/public` (código fora de `public_html`); SSL pelo AutoSSL.
3. **Deploy**:
   - código via git;
   - `composer install --no-dev --optimize-autoloader` (publica os arquivos do Filament);
   - assets compilados localmente (`npm run build`);
   - `php artisan migrate --force`, `storage:link`;
   - `config:cache`, `route:cache`, `view:cache`;
   - primeiro admin com `php artisan app:create-admin`.
4. **`.env` de produção**:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com https;
   - banco com usuário MySQL exclusivo;
   - SMTP com SPF/DKIM;
   - Asaas (primeiro sandbox no servidor, depois produção);
   - permissão 600.
5. **Cron** (cPanel, a cada minuto): `php artisan schedule:run` (cobre `orders:expire` e `payments:sync`).
6. **Webhook do Asaas** com a URL `https://SUBDOMINIO/webhooks/asaas` e o token igual ao do `.env` do servidor (passo a passo no README).
7. **Backup diário** do banco (`mysqldump`, 7 dias) e cópia externa periódica.
8. **Segurança**:
   - revisão geral;
   - WordPress no mesmo cPanel: atualizar, remover plugins sem uso, 2FA no cPanel e no Asaas;
   - verificar se a chave de API do Asaas pode ser restrita (bloquear transferências/saques via API ou limitar por IP).
9. **Acessibilidade e responsividade**: revisão final.
10. **Testes ponta a ponta** com Playwright nos fluxos críticos.
11. **Checklist de publicação**: incluindo as pendências jurídicas e do Asaas acima, e uma execução completa no sandbox dentro da HostGator antes de ativar o Asaas de produção.

## Ambiente local (resumo)

- MySQL no Docker: abra o Docker Desktop e rode `docker compose up -d` (porta 3307).
- Servidor: `composer dev` ou `php artisan serve`.
- Dados de demonstração: `php artisan db:seed` (gera novas senhas, mostradas só no terminal).
- PHP 8.3 (winget) com `php.ini` próprio e certificados raiz (`extras/ssl/cacert.pem`) configurados para HTTPS.
- Asaas sandbox configurado no `.env` (chave de API preenchida; token do webhook vazio de propósito).
