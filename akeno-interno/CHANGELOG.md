# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [2.0.0] - 2024-01-XX

### Adicionado

- Refatoração completa para estrutura orientada a objetos
- Sistema de cache para resolução de URLs e informações de posts
- Validação de links em tempo real no editor
- Templates pré-configurados de botões
- Suporte RTL completo
- Melhorias de acessibilidade (ARIA labels, focus states)
- Internacionalização completa (text domain, strings traduzíveis)
- Limpeza automática de transients expirados
- Suporte a preferências de movimento reduzido
- Suporte a modo de alto contraste
- Documentação completa (README, CHANGELOG, hooks)

### Melhorado

- Segurança: Nonce dinâmico por requisição AJAX
- Segurança: Verificação de capabilities em todas as ações AJAX
- Segurança: Validação de posts antes de renderizar
- Performance: Cache de resolução de URLs (1 hora)
- Performance: Cache de informações de posts (1 hora)
- Performance: Cache de sugestões (30 minutos)
- Performance: Debounce otimizado (300ms)
- UX: Preview melhorado no editor
- UX: Validação visual de links quebrados
- UX: Sugestões clicáveis no editor
- Código: Padrões WordPress Coding Standards
- Código: PHPDoc em todas as funções/métodos
- Código: Separação de concerns

### Corrigido

- Validação de existência de posts antes de renderizar
- Sanitização reforçada de todos os inputs
- Escape adequado de todos os outputs HTML
- Tratamento de erros nas chamadas AJAX
- Problemas de performance com múltiplas requisições

### Alterado

- Versão mínima do WordPress: 5.8
- Versão mínima do PHP: 7.4
- Estrutura de classes (singleton pattern)

## [1.0.0] - 2024-01-XX

### Adicionado

- Bloco Gutenberg inicial
- Sistema de tracking básico
- 13 variantes de estilo
- Integração com Yoast SEO
- Sugestões via Google Suggest API
- Shortcode básico
- Compatibilidade com bloco legado
