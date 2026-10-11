# Sistema de design — "talão de rifa"

A identidade vem do objeto que todo mundo associa a uma ação entre amigos: o bilhete com canhoto picotado. É o único elemento marcante; o resto é quieto e legível.

Os tokens ficam em `resources/css/app.css` (bloco `@theme`). Use sempre os nomes abaixo, nunca cores soltas.

## Cores

| Token | Hex | Uso |
|---|---|---|
| `papel` | `#F5F7F8` | Fundo das páginas |
| `cartao` | `#FFFFFF` | Bilhetes, cards, cabeçalho, rodapé |
| `tinta` | `#12343B` | Texto principal, botões secundários, foco |
| `tinta-suave` | `#4A6066` | Texto de apoio (contraste ≥ 4,5:1 sobre `papel` e `cartao`) |
| `linha` | `#D3DCDE` | Bordas e picote |
| `talao` / `talao-escuro` / `talao-claro` | `#FFC83D` / `#F0B000` / `#FFF4D6` | **Só** ações principais (botão "Participar"), número selecionado, destaque |
| `carimbo` / `carimbo-claro` | `#B8322A` / `#FBECEB` | Vendido, erro, cancelado |
| `ok` / `ok-claro` | `#1D7A4C` / `#E6F4EC` | Aberta, confirmado |
| `aviso` / `aviso-claro` | `#845400` / `#FDF3E1` | Em breve, pausada, aguardando |

Texto sobre `talao` é sempre `tinta` (nunca branco).

## Tipografia

| Papel | Fonte | Uso |
|---|---|---|
| Títulos | Archivo Black (`font-display`) | H1, títulos de seção, título da promoção. Com moderação. |
| Corpo | Archivo (`font-sans`) | Todo o resto |
| Números e valores | IBM Plex Mono (`.num` / `font-mono`) | Números da rifa, preços, contagens, códigos |

As fontes são baixadas no build e servidas pelo próprio site (sem chamadas a terceiros).

## Componentes

| Componente | Onde | Observação |
|---|---|---|
| `.btn .btn-primary` | Ação principal da tela (uma por área) | Estados: `disabled`/`aria-disabled` (indisponível), `aria-busy="true"` (carregando, mostra spinner) |
| `.btn .btn-secondary` | Ações secundárias | Borda `tinta` |
| `.bilhete` + `.bilhete-canhoto` | Cards de promoção, etapas, resumo da promoção, telas de login | Canhoto embaixo; com `.bilhete-horizontal`, canhoto à direita a partir de 640px |
| `<x-phase-badge>` | Situação da promoção | Ícone + texto: nunca só cor |
| `<x-sales-progress>` | Números vendidos | `role="progressbar"` com valores |
| `<x-promotion-card>` | Listagens | Título com link "esticado"; botão "Participar" só quando aberta |
| `.prose-pagina` | Texto em Markdown | Citação (`>`) vira aviso amarelo |

## Espaçamento, bordas e sombras

- Escala do Tailwind (múltiplos de 4px). Seções: `py-16`/`pt-20`; cards: `p-5`/`p-6`.
- Raio: `--radius-bilhete` (14px) em bilhetes e cards; 10px em campos e botões.
- Sombra só no bilhete do topo da página inicial. O resto usa borda `linha`.

## Movimento

Um único momento animado: na página inicial, o bilhete entra e o carimbo "Confirmado" bate uma vez. Com `prefers-reduced-motion`, nada anima.

## Acessibilidade

- Foco visível em tudo: contorno de 3px `tinta`.
- Link "Pular para o conteúdo" no início de cada página pública.
- Menu do celular com `<details>` nativo (funciona sem JavaScript e com teclado).
- Alvos de toque com pelo menos 44px de altura.
- Estados nunca dependem só da cor (ícone e texto juntos).
