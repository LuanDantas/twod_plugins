# Akeno Interno

Bloco de botões para manter o usuário navegando no site. Design otimizado para celular e links internos já sinalizados para o post principal.

## Descrição

O **Akeno Interno** é um plugin WordPress que adiciona um bloco Gutenberg personalizado para criar botões de navegação interna. Ideal para manter os usuários navegando dentro do seu site, com design responsivo e sistema de tracking de origem.

## Características

- ✅ Bloco Gutenberg nativo
- ✅ Até 3 botões por bloco
- ✅ 13 variantes de estilo diferentes
- ✅ Sistema de tracking de origem (ROI)
- ✅ Resolução inteligente de URLs (ID, slug ou URL completa)
- ✅ Emojis automáticos baseados em palavras-chave
- ✅ Integração com Yoast SEO
- ✅ Sugestões de conteúdo via Google Suggest API
- ✅ Templates pré-configurados
- ✅ Validação de links em tempo real
- ✅ Suporte RTL
- ✅ Acessibilidade (ARIA labels, focus states)
- ✅ Shortcode para uso em qualquer lugar

## Instalação

1. Faça upload da pasta `akeno-interno` para `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. O bloco estará disponível no editor Gutenberg na categoria "Widgets"

## Uso

### No Editor Gutenberg

1. Adicione o bloco "Akeno Interno" ao seu post/página
2. Configure os botões no painel lateral:
   - **Texto do botão**: Texto exibido no botão
   - **Destino**: Post ID, slug ou URL completa
   - **Ícone**: Emoji opcional
   - **Src individual**: ID de origem personalizado (opcional)
3. Escolha a variante de estilo
4. Ative/desative o tracking de ROI

### Shortcode

```php
[akeno_buttons buttons='[{"text":"Meu Botão","dest":"123","icon":"📱"}]' variant="primary" src_global="1"]
```

**Parâmetros:**

- `buttons`: JSON array com configuração dos botões
- `variant`: Estilo do botão (primary, secondary, outline, inverse, etc.)
- `src_global`: Ativar tracking (1 ou 0)

### Templates

O plugin inclui templates pré-configurados:

- Padrão (1 botão)
- Navegação Dupla (2 botões)
- Navegação Tripla (3 botões)

## Variantes de Estilo

- **Primário**: Botão azul sólido
- **Secundário**: Botão preto sólido
- **Outline**: Botão com borda apenas
- **Inverso**: Botão branco com borda azul (padrão)
- **CTA**: Botão verde de call-to-action
- **Pill**: Botão com bordas arredondadas
- **Gradient**: Botão com gradiente
- **Shadow**: Botão com sombra destacada
- **Soft**: Botão com fundo suave
- **Flat**: Botão plano sem borda
- **Accent**: Botão roxo
- **Horizontal**: Layout horizontal
- **Grande**: Botão maior

## Sistema de Tracking

O plugin adiciona parâmetros de query string aos links internos para rastrear a origem:

```
?tp=new&src=123
```

Onde `src` é o ID do post de origem. Isso permite:

- Rastrear de onde os usuários vieram
- Manter o contexto de navegação
- Integrar com sistemas de analytics

## Hooks e Filtros

### Filtros

#### `akeno_render_block`

Modifica o HTML renderizado do bloco.

```php
add_filter( 'akeno_render_block', function( $output, $attrs ) {
    // Modificar $output
    return $output;
}, 10, 2 );
```

## Requisitos

- WordPress 5.8 ou superior
- PHP 7.4 ou superior

## Tradução

O plugin está preparado para tradução. Arquivos de tradução podem ser criados em `/languages/`.

Para traduzir:

1. Use ferramentas como Poedit ou Loco Translate
2. Arquivo base: `akeno-interno.pot`
3. Text domain: `akeno-interno`

## Suporte RTL

O plugin inclui suporte completo para idiomas RTL (Right-to-Left) como árabe e hebraico.

## Acessibilidade

- Labels ARIA em todos os botões
- Estados de foco visíveis
- Suporte a navegação por teclado
- Respeita preferências de movimento reduzido

## Troubleshooting

### Botões não aparecem

- Verifique se o destino está correto (ID, slug ou URL válida)
- Confirme que o post destino existe e está publicado
- Verifique se há erros no console do navegador

### Tracking não funciona

- Certifique-se de que `includeSrcGlobal` está ativado
- Verifique se o link é interno (mesmo domínio)
- Confirme que o post de origem tem um ID válido

### Sugestões não carregam

- Verifique sua conexão com a internet
- A API do Google pode estar temporariamente indisponível
- Tente usar uma palavra-chave diferente

## Changelog

Veja [CHANGELOG.md](CHANGELOG.md) para histórico completo de versões.

## Licença

GPLv2 or later

## Autor

TwoD

## Suporte

Para suporte, abra uma issue no repositório do plugin.
