# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [3.0.0] - 2024-01-XX

### Adicionado

- Refatoração completa para estrutura singleton
- Sistema de cache estendido (24 horas para metadados)
- Scraping assíncrono via WP Cron no frontend
- Validação de URLs antes de processar
- Rate limiting para criação de tokens
- Validação de formato de tokens
- Formatação de preço localizada (USD, BRL, EUR)
- Fallback de ícones quando não disponível
- Suporte RTL completo
- Internacionalização completa (text domain, strings traduzíveis)
- Limpeza automática de transients expirados
- Cache de contagem de parágrafos
- Documentação completa (README, CHANGELOG)
- Suporte a preferências de movimento reduzido
- Suporte a modo de alto contraste
- Melhorias de acessibilidade (focus states, ARIA)

### Melhorado

- Segurança: Validação de capabilities em todas as ações
- Segurança: Validação de formato de tokens
- Segurança: Rate limiting para tokens
- Segurança: Sanitização reforçada de URLs
- Performance: Cache de metadados estendido (12h → 24h)
- Performance: Scraping assíncrono no frontend
- Performance: Cache de contagem de parágrafos
- Performance: Validação de estrutura HTML/JSON antes de parsing
- UX: Fallback de ícones quando não carregam
- UX: Formatação de preço localizada
- Código: Padrões WordPress Coding Standards
- Código: PHPDoc em todas as funções/métodos
- Código: Separação de concerns (validação, fetching, rendering)
- Código: Tratamento de erros melhorado

### Corrigido

- Validação de estrutura HTML/JSON antes de fazer parsing
- Tratamento de falhas de scraping sem quebrar o admin
- Validação de URLs de Google Play/App Store
- Limpeza de transients expirados
- Verificação de capabilities no metabox e save
- Problemas de performance com scraping síncrono

### Alterado

- Versão mínima do WordPress: 5.8
- Versão mínima do PHP: 7.4
- Estrutura de classes (singleton pattern)
- TTL do cache de metadados (12h → 24h)

## [2.0.0] - 2024-01-XX

### Adicionado

- Sistema de tokens temporários `/go/<token>`
- Delay configurável para redirecionamento
- Busca automática de metadados de apps
- Cache de metadados (12 horas)
- Metabox para configurar múltiplos apps
- Inserção automática em parágrafos específicos
- Shortcode para uso manual
- Detecção automática de cor do tema

### Melhorado

- Interface do metabox
- Performance com cache de metadados
- Suporte a Google Play e App Store

## [1.0.0] - 2024-01-XX

### Adicionado

- Versão inicial do plugin
