# RedirectID AD

Cria cards de apps (nome/ícone/tamanho/preço/nota) e URLs internas `/go/<token>` para acionar regras de interstitial do site. **NÃO exibe anúncios** — as regras de interstitial ficam no seu script do site.

## Descrição

O **RedirectID AD** é um plugin WordPress que permite criar cards de aplicativos móveis com informações obtidas automaticamente das lojas Google Play e App Store. Os cards são inseridos automaticamente no conteúdo dos posts, e os links de download passam por um sistema de tokens temporários (`/go/<token>`) que permite acionar regras de interstitial antes do redirecionamento.

## Características

- ✅ Metabox intuitivo para configurar múltiplos apps por post
- ✅ Inserção automática de cards em parágrafos específicos
- ✅ Sistema de tokens temporários (`/go/<token>`)
- ✅ Delay configurável para redirecionamento (ajuda interstitials)
- ✅ Busca automática de metadados (nome, ícone, rating, tamanho, preço)
- ✅ Suporte a Google Play e App Store
- ✅ Cache inteligente de metadados (24 horas)
- ✅ Scraping assíncrono para melhor performance
- ✅ Validação de URLs antes de processar
- ✅ Rate limiting para segurança
- ✅ Suporte RTL
- ✅ Formatação de preço localizada
- ✅ Fallback de ícones quando não disponível
- ✅ Shortcode para uso manual

## Instalação

1. Faça upload da pasta `gam-ad` para `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Vá em **Configurações > RedirectID AD** para configurar opções gerais
4. Ao editar um post, você verá o metabox "Apps (RedirectID)" na lateral

## Uso

### Configurando Apps em um Post

1. Edite ou crie um novo post
2. No metabox "Apps (RedirectID)" na lateral:
   - Clique em **"+ adicionar app"**
   - Cole a URL do Google Play (opcional)
   - Cole a URL do App Store (opcional)
   - Escolha em qual parágrafo inserir o card
3. Salve o post

### Configurações Gerais

Vá em **Configurações > RedirectID AD** para configurar:

- **Tempo de vida do token**: Quanto tempo o token `/go/` permanece válido (padrão: 10 minutos)
- **Espera na /go/**: Delay antes do redirecionamento em milissegundos (padrão: 150ms)
- **Cor do botão**: Cor fallback para os botões de download

### Shortcode

Use o shortcode para inserir um card manualmente:

```php
[rid_app_card gplay="https://play.google.com/store/apps/details?id=com.example.app" appstore="https://apps.apple.com/app/id123456789"]
```

## Sistema de Tokens

O plugin cria URLs temporárias no formato `/go/<token>/` que:

1. Armazenam o destino final em um transient
2. Têm tempo de vida configurável (padrão: 10 minutos)
3. Permitem delay configurável antes do redirecionamento
4. Facilitam o acionamento de regras de interstitial

**Exemplo de fluxo:**

```
Usuário clica → /go/abc123xyz/ → (delay) → https://play.google.com/...
```

## Como Funciona o Scraping

O plugin busca automaticamente:

- **Google Play**: Nome, ícone, rating via scraping HTML
- **App Store**: Nome, ícone, rating, tamanho, preço via iTunes API

Os dados são cacheados por 24 horas para melhor performance.

### Scraping Assíncrono

No frontend, o scraping é feito de forma assíncrona via WP Cron para não travar o carregamento da página. No admin, o scraping é síncrono para preview imediato.

## Hooks e Filtros

### Filtros

Nenhum filtro disponível no momento. Em breve.

### Actions

Nenhuma action disponível no momento. Em breve.

## Requisitos

- WordPress 5.8 ou superior
- PHP 7.4 ou superior
- Conexão com internet (para buscar metadados das lojas)

## Tradução

O plugin está preparado para tradução. Arquivos de tradução podem ser criados em `/languages/`.

Para traduzir:

1. Use ferramentas como Poedit ou Loco Translate
2. Arquivo base: `redirectid-ad.pot`
3. Text domain: `redirectid-ad`

## Suporte RTL

O plugin inclui suporte completo para idiomas RTL (Right-to-Left) como árabe e hebraico.

## Segurança

- Validação de tokens (formato e existência)
- Rate limiting para criação de tokens
- Sanitização de todas as URLs
- Validação de capabilities em todas as ações admin
- Nonce verification em todas as requisições

## Troubleshooting

### Cards não aparecem

- Verifique se há apps configurados no metabox do post
- Confirme que o post está publicado
- Verifique se as URLs das lojas estão corretas

### Metadados não carregam

- Verifique sua conexão com a internet
- As APIs das lojas podem estar temporariamente indisponíveis
- Verifique se as URLs estão no formato correto:
  - Google Play: `https://play.google.com/store/apps/details?id=...`
  - App Store: `https://apps.apple.com/app/id...`

### Tokens não funcionam

- Verifique se as rewrite rules foram atualizadas (vá em **Configurações > Links Permanentes** e salve)
- Confirme que o tempo de vida do token não expirou
- Verifique os logs de erro do WordPress

### Performance lenta

- O plugin usa cache de 24 horas para metadados
- Scraping assíncrono no frontend evita travamentos
- Se necessário, aumente o tempo de cache nas configurações

## Changelog

Veja [CHANGELOG.md](CHANGELOG.md) para histórico completo de versões.

## Licença

GPLv2 or later

## Autor

TwoD

## Suporte

Para suporte, abra uma issue no repositório do plugin.
