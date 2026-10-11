# Projeto: Plataforma de Ações entre Amigos

## 1. Objetivo do projeto

Desenvolva uma plataforma web responsiva para cadastro, divulgação e gerenciamento de ações entre amigos, permitindo que os responsáveis criem promoções com números disponíveis para participação dos usuários.

Os participantes devem poder visualizar as promoções, selecionar um ou vários números, realizar o pagamento por Pix ou cartão de crédito/débito, acompanhar o status da compra e receber um comprovante com a identificação dos números adquiridos.

O sistema deve priorizar segurança, facilidade de uso, transparência, confiabilidade e uma experiência visual moderna.

**Importante:** antes de implementar funcionalidades, analise a estrutura do projeto existente, identifique as tecnologias, os padrões arquiteturais, as bibliotecas e as convenções já utilizadas. Preserve o que estiver funcionando. Caso o projeto esteja vazio, proponha uma arquitetura adequada e documente as decisões.

Não implemente funcionalidades fictícias como se fossem integrações reais. Quando alguma credencial ou serviço externo não estiver configurado, utilize interfaces de integração e um ambiente de testes claramente identificado.

## 2. Conceito visual e experiência do usuário

Crie uma interface moderna, profissional, intuitiva e responsiva, com inspiração em plataformas de campanhas, promoções e comércio eletrônico.

A identidade visual deve transmitir confiança e facilitar a identificação das ações disponíveis.

Diretrizes visuais:

- Layout limpo, com boa hierarquia visual.
- Fundo predominantemente claro.
- Cores principais que transmitam confiança, com uma cor de destaque para botões de ação.
- Cards de promoção com imagens, título, preço, progresso e situação da campanha.
- Tipografia legível e espaçamento consistente.
- Ícones intuitivos e padronizados.
- Botões com estados de carregamento, sucesso, erro e indisponibilidade.
- Feedback visual imediato ao selecionar números.
- Design adaptável para computadores, tablets e celulares.
- Acessibilidade, navegação por teclado, contraste adequado e identificação textual dos estados.
- Mensagens de erro compreensíveis, evitando termos técnicos para o usuário final.

Defina um sistema de design com cores, tipografia, espaçamentos, bordas, sombras, componentes reutilizáveis e estados interativos.

## 3. Página inicial pública

A página inicial deve ser a principal porta de entrada da plataforma.

### Cabeçalho

Criar um cabeçalho contendo:

- Logotipo da plataforma.
- Link para a página inicial.
- Link para promoções disponíveis.
- Link para minhas compras.
- Link para consultar um pedido ou comprovante.
- Botão de entrar.
- Botão de cadastrar-se.
- Menu responsivo para dispositivos móveis.

Se o usuário estiver autenticado, exibir acesso à sua conta.

### Seção principal (hero)

Criar uma seção de destaque com:

- Título chamativo que explique o propósito da plataforma.
- Texto curto explicando como participar.
- Botão principal "Ver promoções".
- Botão secundário "Como funciona".
- Imagem ou ilustração relacionada às campanhas.

O conteúdo deve ser editável futuramente por um administrador, sempre que aplicável.

### Promoções em destaque

Apresentar as promoções em cards contendo:

- Imagem principal.
- Nome da promoção.
- Descrição resumida.
- Prêmio ou benefício anunciado.
- Valor de cada número.
- Quantidade total de números.
- Quantidade de números vendidos ou confirmados.
- Barra de progresso.
- Data de encerramento, quando aplicável.
- Situação da promoção.
- Botão "Participar".

Criar filtros por categoria, status e outras opções relevantes.

Adicionar pesquisa por nome da promoção.

Exibir estados de carregamento, resultados vazios e falhas de carregamento.

Não mostrar como concluída uma promoção que ainda esteja aberta, nem exibir informações de disponibilidade desatualizadas como se fossem definitivas.

### Seção "Como funciona"

Apresentar um fluxo visual em quatro etapas:

1. Escolha uma promoção.
2. Selecione seus números.
3. Realize o pagamento.
4. Receba seu comprovante.

### Rodapé

Incluir:

- Sobre a plataforma.
- Termos de uso.
- Política de privacidade.
- Política de cancelamento e reembolso.
- Contato e suporte.
- Informações sobre os responsáveis pela promoção.
- Regras e informações legais aplicáveis.

Não inventar autorizações, certificações ou garantias legais. Deixar configuráveis as informações que dependam do responsável e da análise jurídica.

## 4. Cadastro e autenticação de usuários

Implementar autenticação segura.

Funcionalidades:

- Cadastro com nome, e-mail e senha.
- Validação de campos.
- Confirmação de e-mail, quando configurada.
- Login e logout.
- Recuperação de senha.
- Alteração de senha.
- Perfil do usuário.
- Histórico de compras.
- Consulta de comprovantes.
- Encerramento seguro da sessão.

Preparar a estrutura para diferenciar os seguintes perfis:

- Administrador da plataforma.
- Responsável por promoções.
- Participante.

O administrador da plataforma deve poder criar, ativar, bloquear e gerenciar usuários responsáveis por promoções.

Não permitir que um usuário comum se transforme em administrador simplesmente alterando dados enviados pelo navegador.

Implementar controle de acesso no servidor, validação de permissões e proteção contra acesso indevido aos dados de outros usuários.

## 5. Área administrativa da plataforma

Criar um painel administrativo com layout próprio, navegação lateral e dashboard inicial.

### Dashboard

Exibir indicadores relevantes, de acordo com as permissões do usuário:

- Total de promoções.
- Promoções ativas.
- Promoções encerradas.
- Números reservados.
- Números vendidos e pagos.
- Pedidos pendentes.
- Pagamentos confirmados.
- Valores recebidos.
- Pedidos cancelados ou expirados.

Diferenciar faturamento bruto, pagamentos efetivamente confirmados, reembolsos e valores pendentes. Não tratar reservas ou pagamentos pendentes como receita recebida.

### Gerenciamento de usuários

Permitir ao administrador:

- Criar usuários responsáveis por promoções.
- Editar dados cadastrais.
- Ativar e desativar contas.
- Consultar as promoções vinculadas a cada responsável.
- Consultar histórico de atividades.
- Gerenciar permissões.

Registrar ações administrativas importantes em um log de auditoria.

### Gerenciamento de promoções

Criar uma tela de listagem com pesquisa, filtros, paginação e ações disponíveis de acordo com o status.

Permitir criar, visualizar, editar, duplicar, publicar, pausar e encerrar promoções, respeitando as regras de negócio.

Cada promoção deverá possuir:

- Nome.
- Slug ou identificador público.
- Descrição completa.
- Imagem principal.
- Galeria opcional de imagens.
- Prêmio ou benefício.
- Preço unitário.
- Quantidade total de números.
- Formato dos números, como 0001 a 1000.
- Data e hora de início.
- Data e hora de encerramento.
- Regras de participação.
- Informações sobre o responsável.
- Métodos de pagamento habilitados.
- Limite mínimo e máximo de números por pedido, se aplicável.
- Status da campanha.
- Informações e regras de apuração do resultado, quando aplicáveis.

Permitir visualizar uma prévia da página pública antes da publicação.

Validar os campos obrigatórios e impedir alterações que comprometam compras já confirmadas.

Qualquer alteração que afete o preço, a quantidade de números ou as condições de uma promoção publicada deve respeitar regras de integridade e auditoria.

Não permitir a exclusão física de registros relacionados a pagamentos, pedidos ou comprovantes. Utilizar cancelamento ou arquivamento conforme as regras aplicáveis.

## 6. Página de detalhes da promoção

Ao acessar uma promoção, o participante deverá visualizar:

- Imagem e nome.
- Descrição completa.
- Prêmio ou benefício.
- Valor unitário.
- Quantidade de números disponíveis.
- Progresso da campanha.
- Data de encerramento.
- Informações do responsável.
- Regras de participação.
- Botão para iniciar a seleção.

Disponibilizar a seleção de números de forma simples e rápida.

## 7. Seleção de números

Criar uma interface de seleção com grade responsiva de números.

Cada número deverá apresentar um estado visual claramente identificável:

- Disponível.
- Selecionado pelo usuário atual.
- Temporariamente reservado.
- Vendido e confirmado.
- Indisponível por outro motivo, quando aplicável.

Não depender apenas das cores para comunicar os estados.

### Comportamentos

- Permitir selecionar vários números.
- Permitir desmarcar números ainda disponíveis ao usuário.
- Exibir a quantidade selecionada.
- Exibir o subtotal em tempo real.
- Permitir limpar a seleção.
- Permitir pesquisar um número específico.
- Permitir escolher números aleatórios, se habilitado na promoção.
- Oferecer filtros para facilitar a navegação em grades grandes.
- Exibir mensagens claras quando um número ficar indisponível.

Exemplo:

Número 0012 — R$ 5,00  
Número 0013 — R$ 5,00  
Número 0014 — R$ 5,00

Total de 3 números: R$ 15,00.

### Regra crítica de concorrência

A disponibilidade mostrada na interface é apenas informativa. A disponibilidade definitiva deve ser validada pelo servidor.

Implementar reserva atômica dos números utilizando transações no banco de dados e mecanismos de concorrência adequados.

Quando o participante avançar para o checkout:

1. Validar todos os números solicitados.
2. Confirmar que pertencem à promoção correta.
3. Verificar que continuam disponíveis.
4. Reservá-los atomicamente.
5. Criar uma reserva com identificador único e prazo de expiração.
6. Associar a reserva ao pedido e ao usuário, ou a uma sessão de checkout segura quando o fluxo permitir convidados.
7. Exibir o tempo restante para concluir o pagamento.
8. Impedir que outra pessoa reserve os mesmos números simultaneamente.

Se algum número não estiver mais disponível, não concluir parcialmente a operação sem informar o usuário. Apresentar os números conflitantes e permitir que ele revise sua seleção.

Quando a reserva expirar sem pagamento confirmado, liberar os números de maneira segura.

**Atenção:** uma consulta seguida de uma gravação sem proteção contra concorrência não é suficiente. A implementação deve impedir efetivamente a venda duplicada, inclusive quando várias requisições ocorrerem ao mesmo tempo.

## 8. Checkout e pedidos

Criar um checkout simples, dividido em etapas claras:

1. Revisão dos números selecionados.
2. Identificação do comprador.
3. Escolha do método de pagamento.
4. Confirmação das condições aplicáveis.
5. Geração ou encaminhamento do pagamento.
6. Acompanhamento do status.

Exibir resumo do pedido contendo:

- Nome da promoção.
- Identificação do pedido.
- Números reservados.
- Quantidade.
- Valor unitário.
- Valor total.
- Método de pagamento.
- Prazo de expiração da reserva.
- Status atual.

Calcular o valor total no servidor. Nunca confiar exclusivamente no preço, no subtotal ou na lista de números enviados pelo navegador.

Criar identificadores únicos para pedidos e evitar a criação de cobranças duplicadas em decorrência de cliques repetidos, recarregamento de página ou repetição de requisições.

## 9. Integração com pagamentos

Implementar uma arquitetura desacoplada dos provedores de pagamento, permitindo integrar serviços diferentes futuramente.

### Pix

Permitir:

- Gerar uma cobrança Pix por meio de um provedor compatível.
- Exibir QR Code de pagamento.
- Exibir o código Pix copia e cola.
- Copiar o código.
- Mostrar valor e vencimento.
- Consultar o status do pagamento.
- Atualizar o pedido após confirmação.
- Exibir instruções em caso de expiração ou falha.

### Cartão de crédito e débito

Preparar integração com um provedor que ofereça os métodos necessários.

Permitir, conforme os recursos do provedor:

- Pagamento por cartão de crédito.
- Pagamento por cartão de débito.
- Exibição do status da transação.
- Tratamento de pagamentos recusados.
- Tratamento de falhas e cancelamentos.
- Fluxos de autenticação adicional, quando exigidos.

Não armazenar números completos de cartão, código de segurança ou outros dados sensíveis de pagamento no banco de dados da plataforma.

Preferir checkout hospedado ou componentes seguros e tokenizados do provedor.

### Segurança da integração

- Guardar credenciais somente no servidor.
- Utilizar variáveis de ambiente.
- Implementar validação de assinaturas de webhooks.
- Validar eventos e valores recebidos.
- Garantir idempotência dos eventos.
- Tratar notificações repetidas ou fora de ordem.
- Registrar os identificadores externos das transações.
- Não confiar em redirecionamentos do navegador para confirmar pagamentos.
- Não liberar números apenas porque o usuário visualizou uma página de sucesso.
- Disponibilizar ambiente de testes separado do ambiente de produção.

Não simular pagamentos aprovados em produção.

## 10. Confirmação da compra e comprovante

Após a confirmação legítima do pagamento pelo provedor, o sistema deverá:

1. Validar o evento de confirmação.
2. Verificar a correspondência entre pedido, valor e transação.
3. Confirmar o pedido de forma idempotente.
4. Transformar os números reservados em números vendidos e confirmados.
5. Liberar outras reservas expiradas conforme as regras de concorrência.
6. Registrar a data e hora da confirmação.
7. Gerar o comprovante.
8. Disponibilizar o comprovante ao comprador.

O comprovante deverá conter:

- Nome da plataforma.
- Identificação da promoção.
- Identificação do responsável.
- Nome do comprador, conforme os dados permitidos.
- Número do pedido.
- Lista completa dos números confirmados.
- Quantidade adquirida.
- Valor unitário.
- Valor total.
- Método de pagamento.
- Status do pagamento.
- Data e hora da confirmação.
- Identificador verificável do comprovante.
- Código QR opcional para consulta de autenticidade.
- Link ou instruções para consultar o pedido.

Disponibilizar o comprovante na área do usuário e permitir sua impressão ou geração em PDF.

Opcionalmente, preparar envio por e-mail após a confirmação.

A consulta pública de autenticidade não deve revelar CPF, e-mail, telefone ou outros dados pessoais do comprador.

**Garantia de integridade:** o comprovante precisa refletir os números efetivamente confirmados no banco de dados. Não permitir que o usuário altere o conteúdo do comprovante enviado ao navegador e seja capaz de validá-lo como legítimo.

## 11. Área do participante

Criar uma área pessoal com:

- Dados cadastrais.
- Histórico de pedidos.
- Pedidos aguardando pagamento.
- Pedidos confirmados.
- Pedidos expirados ou cancelados.
- Consulta dos números adquiridos.
- Visualização e impressão dos comprovantes.
- Consulta dos detalhes da promoção.
- Atualização dos dados pessoais.
- Solicitação de suporte.

Restringir a consulta dos pedidos aos seus respectivos proprietários, exceto nos casos em que um administrador autorizado tenha permissão de acesso.

## 12. Acompanhamento em tempo real

A interface de seleção deverá refletir as mudanças de disponibilidade de números.

Avalie a implementação de atualização em tempo real por WebSocket, SSE ou mecanismo de atualização periódica, conforme a arquitetura escolhida.

Requisitos:

- Atualizar o estado dos números quando forem reservados, liberados ou vendidos.
- Evitar que a interface mantenha números desatualizados como disponíveis.
- Reconectar após perda de conexão.
- Revalidar a disponibilidade antes de confirmar uma reserva.
- Tratar duas pessoas tentando selecionar o mesmo número.
- Manter a decisão final de disponibilidade no servidor.

A atualização visual em tempo real não substitui as garantias transacionais do banco de dados.

## 13. Regras de negócio e estados

Definir uma máquina de estados documentada para promoções, números, reservas, pedidos e pagamentos.

Exemplos de estados de uma promoção:

- Rascunho.
- Agendada.
- Aberta.
- Pausada.
- Encerrada.
- Cancelada.

Exemplos de estados de uma reserva:

- Ativa.
- Convertida em venda.
- Expirada.
- Liberada.

Exemplos de estados de um pedido:

- Aguardando pagamento.
- Pagamento em processamento.
- Confirmado.
- Pagamento recusado.
- Expirado.
- Cancelado.
- Reembolsado, quando aplicável.

Não misturar o estado do pedido com o estado da transação financeira.

Documentar as transições permitidas e implementar validações no servidor.

Prever situações como:

- Pagamento confirmado após a expiração aparente da reserva.
- Webhook recebido mais de uma vez.
- Pagamento recusado.
- Cobrança expirada.
- Solicitação de reembolso.
- Falha de comunicação com o provedor.
- Tentativas simultâneas de reserva.
- Pedido criado, mas cobrança não gerada.
- Promoção encerrada enquanto existem pagamentos pendentes.

Definir políticas de recuperação e conciliação financeira. Nunca atribuir um número já confirmado a outro comprador como consequência automática de um evento tardio sem resolver a situação financeira e operacional.

## 14. Banco de dados

Projete um modelo de dados normalizado, com chaves estrangeiras, índices, restrições e transações adequadas.

Avalie entidades como:

- Users.
- Roles e Permissions.
- Promotions.
- PromotionImages.
- PromotionNumbers.
- NumberReservations.
- Orders.
- OrderItems.
- Payments.
- PaymentEvents.
- Receipts.
- AuditLogs.
- Notifications.
- Refunds, se aplicável.

Cada número deverá estar associado a uma promoção específica e possuir uma identidade única dentro dessa promoção.

Implemente restrições que impeçam duplicidade de números e múltiplas vendas confirmadas para a mesma combinação de promoção e número.

Defina uma estratégia para reservas temporárias expiradas, evitando depender exclusivamente de tarefas executadas pelo navegador.

Documente o esquema, os relacionamentos, os índices e as migrations.

## 15. Segurança, privacidade e conformidade

Adotar boas práticas de segurança para aplicações web.

Incluir:

- Validação de entrada no servidor.
- Consultas parametrizadas ou ORM seguro.
- Proteção contra SQL injection, XSS e CSRF quando aplicável.
- Controle de acesso baseado em permissões.
- Rate limiting em autenticação, checkout e endpoints sensíveis.
- Proteção contra enumeração de pedidos.
- Cookies seguros e gerenciamento de sessão adequado.
- Segredos fora do código-fonte.
- Logs sem exposição de credenciais ou dados sensíveis.
- Política de retenção de dados.
- Rotinas de backup e recuperação.
- Registro de operações administrativas.
- Conformidade com a LGPD.
- Proteção contra requisições de pagamento forjadas.
- Monitoramento de erros e falhas operacionais.

Antes da publicação, verificar a legislação brasileira aplicável ao formato da promoção, à distribuição de prêmios, à publicidade, às cobranças e aos meios de pagamento.

A plataforma deverá permitir configurar os dados e documentos necessários para cada modelo de operação. Não presumir que uma ação denominada "entre amigos" esteja automaticamente dispensada de autorização ou de outros requisitos legais.

Não disponibilizar publicamente campanhas que dependam de autorização ou validação ainda não concluída.

## 16. Tecnologias e arquitetura

Primeiro, inspecione o repositório existente e respeite sua stack.

Se o projeto estiver vazio, recomende uma arquitetura moderna e sustentável, contemplando:

- Front-end responsivo.
- Back-end com API segura.
- Banco de dados relacional, preferencialmente PostgreSQL.
- ORM e migrations.
- Autenticação e autorização.
- Integração com provedor de pagamentos.
- Armazenamento seguro de imagens.
- Geração de comprovantes em PDF.
- Sistema de notificações.
- Testes automatizados.
- Logs e monitoramento.

Caso seja necessário escolher tecnologias, apresente uma recomendação fundamentada antes de iniciar uma implementação extensa. Evite introduzir bibliotecas desnecessárias.

Organize o código em módulos, com responsabilidades bem definidas, tipagem adequada quando suportada e separação entre regras de negócio, persistência e interface.

Crie um arquivo de variáveis de ambiente de exemplo, sem segredos reais, e documentação de instalação.

## 17. Testes obrigatórios

Criar testes unitários, de integração e de ponta a ponta para os fluxos críticos.

Cobrir pelo menos:

1. Cadastro e autenticação.
2. Criação e publicação de promoções.
3. Seleção de vários números.
4. Cálculo correto dos valores.
5. Reserva temporária de números.
6. Expiração e liberação de reservas.
7. Duas pessoas tentando reservar o mesmo número.
8. Duas compras simultâneas envolvendo números sobrepostos.
9. Confirmação de pagamento.
10. Webhooks repetidos.
11. Pagamentos recusados.
12. Geração de comprovantes.
13. Proteção de dados entre usuários.
14. Controle de acesso administrativo.
15. Validação das permissões.
16. Comportamento responsivo.
17. Tratamento de falhas dos provedores externos.

O teste de concorrência deve demonstrar que um número não pode resultar em duas vendas confirmadas, mesmo sob requisições simultâneas.

## 18. Dados de demonstração

Criar dados de demonstração exclusivamente para desenvolvimento, com campanhas claramente identificadas como fictícias.

Não publicar campanhas demonstrativas como reais.

Disponibilizar uma forma segura de popular o banco de desenvolvimento com usuários de teste e promoções de exemplo.

Não inserir senhas padrão inseguras nem credenciais reais no repositório.

## 19. Etapas de desenvolvimento

Trabalhe por fases, sem tentar implementar todo o sistema de uma só vez.

### Fase 1 — Análise e planejamento

- Inspecionar os arquivos existentes.
- Identificar a stack e as dependências.
- Mapear as funcionalidades existentes.
- Identificar riscos e requisitos não definidos.
- Recomendar a arquitetura.
- Criar um plano de implementação.
- Definir os principais fluxos e o modelo de dados.

### Fase 2 — Identidade visual e páginas principais

- Definir o sistema de design.
- Criar a página inicial.
- Criar os cards de promoção.
- Criar a página de detalhes.
- Criar a interface de seleção de números.
- Criar os layouts de autenticação.
- Criar a estrutura do painel administrativo.
- Validar a responsividade.

### Fase 3 — Banco de dados e autenticação

- Implementar o esquema.
- Criar migrations.
- Implementar cadastro e login.
- Implementar papéis e permissões.
- Criar testes básicos de segurança.

### Fase 4 — Gerenciamento de promoções

- Criar e editar promoções.
- Implementar publicação e encerramento.
- Implementar a gestão de números.
- Implementar as validações de negócio.

### Fase 5 — Reservas e checkout

- Implementar reservas atômicas.
- Implementar expiração.
- Criar pedidos e itens.
- Implementar checkout.
- Testar concorrência.

### Fase 6 — Pagamentos

- Integrar Pix.
- Integrar cartão por meio de provedor compatível.
- Implementar webhooks seguros.
- Implementar conciliação e tratamento de falhas.

### Fase 7 — Comprovantes e notificações

- Confirmar compras.
- Gerar comprovantes.
- Criar consulta de autenticidade.
- Implementar histórico de compras.
- Preparar notificações.

### Fase 8 — Testes e preparação para produção

- Executar testes automatizados.
- Corrigir problemas.
- Validar responsividade e acessibilidade.
- Revisar segurança.
- Documentar deploy e configurações.
- Validar os requisitos jurídicos e operacionais.
- Preparar checklist de publicação.

## 20. Forma de trabalho no Claude Code

Siga estas instruções durante a execução:

1. Antes de modificar arquivos, analise o projeto.
2. Não apague ou substitua funcionalidades existentes sem necessidade e justificativa.
3. Não presuma que uma integração está funcionando sem verificá-la.
4. Antes de decisões arquiteturais importantes, explique as opções e a recomendação.
5. Implemente em pequenas etapas verificáveis.
6. Ao terminar cada etapa, execute os testes pertinentes.
7. Corrija os erros encontrados antes de avançar para a próxima etapa.
8. Mantenha a documentação atualizada.
9. Não deixe botões sem ação ou fluxos que terminem em telas sem continuidade.
10. Não utilize dados simulados como substitutos silenciosos de integrações reais.
11. Se faltarem credenciais, requisitos ou decisões essenciais, identifique exatamente o que falta e proponha uma alternativa segura para continuar o desenvolvimento.
12. Não declare uma funcionalidade concluída enquanto não houver implementação e validação compatíveis com seu objetivo.

## 21. Critérios de aceitação

O projeto será considerado funcional quando:

- A página inicial exibir promoções reais cadastradas no sistema.
- Um administrador puder gerenciar usuários e promoções.
- Participantes puderem selecionar vários números.
- O sistema impedir reservas e vendas duplicadas.
- Os números reservados forem liberados corretamente após a expiração.
- Os pagamentos forem confirmados por meio de integração segura.
- Os números forem vinculados ao pedido correto.
- O comprovante apresentar os números efetivamente confirmados.
- O participante puder consultar seu histórico.
- As permissões impedirem o acesso indevido.
- Os principais fluxos estiverem cobertos por testes.
- A interface funcionar em dispositivos móveis e computadores.
- A documentação permitir configurar o ambiente de desenvolvimento e preparar a implantação.

## Primeira tarefa

Comece exclusivamente pela Fase 1.

Inspecione o projeto, apresente o diagnóstico da estrutura existente, a arquitetura recomendada, o modelo inicial de dados, os principais riscos e um plano de execução por etapas.

Não implemente todas as funcionalidades imediatamente. Depois de apresentar o planejamento, aguarde minha aprovação antes de iniciar as alterações estruturais.

O objetivo é construir uma plataforma segura, profissional, escalável e fácil de manter, com uma experiência simples para quem cria as promoções e para quem participa delas.