Plano de Melhorias - Akeno Interno e GAM-AD

1. Correções de Bugs e Melhorias de Segurança
   Akeno Interno
   Nonce dinâmico: Gerar nonce por requisição AJAX ao invés de uma vez no init
   Validação de posts: Validar existência do post destino antes de renderizar botões
   Sanitização: Reforçar sanitização de todos os inputs do editor
   XSS prevention: Garantir escape adequado em todos os outputs HTML
   Capability checks: Adicionar verificação de capabilities nas ações AJAX
   URL validation: Validar formato de URLs antes de processar
   GAM-AD
   Token security: Implementar validação adicional de tokens (rate limiting)
   Scraping validation: Validar estrutura HTML/JSON antes de fazer parsing
   Error handling: Tratar falhas de scraping sem quebrar o admin
   URL sanitization: Validar URLs de Google Play/App Store antes de processar
   Transient cleanup: Adicionar limpeza automática de transients expirados
   Capability checks: Verificar capabilities no metabox e save
2. Otimizações de Performance
   Akeno Interno
   Cache de resolução: Cachear resolução de URLs (post ID/slug → permalink)
   Debounce AJAX: Otimizar debounce de resolução de destinos no editor
   Lazy loading: Carregar preview apenas quando necessário
   Asset optimization: Minificar CSS/JS para produção
   Transient cache: Cachear informações de posts buscados via AJAX
   GAM-AD
   Scraping assíncrono: Mover scraping para background (WP Cron ou ação assíncrona)
   Batch processing: Processar múltiplos apps em uma única requisição quando possível
   Cache de metadados: Estender TTL do cache de metadados (12h → 24h)
   Lazy loading: Carregar metadados apenas quando necessário no frontend
   Transient cleanup: Otimizar limpeza de transients antigos
   Query optimization: Otimizar contagem de parágrafos (evitar wpautop desnecessário)
3. Novas Funcionalidades
   Akeno Interno
   Preview em tempo real: Preview mais interativo no editor
   Templates de botões: Templates pré-configurados para casos comuns
   Analytics integration: Hook para integração com Google Analytics
   A/B testing: Suporte básico para variantes de teste
   Bulk operations: Editar múltiplos botões de uma vez
   Link validation: Validação visual de links quebrados no editor
   GAM-AD
   Preview no admin: Preview do card no metabox antes de salvar
   Bulk import: Importar múltiplos apps via CSV/JSON
   Analytics tracking: Tracking de cliques nos botões de download
   Fallback images: Sistema de fallback quando ícone não carrega
   Custom fields: Campos customizados por app (descrição, tags, etc)
   Position preview: Visualização de onde o card será inserido no conteúdo
   App search: Busca de apps por nome no metabox
   Localização de preço: Formatação de preço por país/moeda
4. Refatoração de Código
   Akeno Interno
   Separação de concerns: Separar lógica de renderização, helpers e AJAX
   Classe principal: Converter para estrutura orientada a objetos
   Constants: Centralizar constantes em classe/config
   Helper functions: Organizar helpers em namespace próprio
   Error handling: Padronizar tratamento de erros
   Code standards: Aderir ao WordPress Coding Standards
   GAM-AD
   Separação de classes: Separar lógica de scraping, rendering e admin
   Service layer: Criar camada de serviço para APIs externas
   Repository pattern: Abstrair acesso a post meta
   Error handling: Sistema unificado de tratamento de erros
   Validation layer: Camada dedicada para validação de dados
   Code standards: Aderir ao WordPress Coding Standards
5. Internacionalização
   Akeno Interno
   Text domain: Adicionar text domain 'akeno-interno'
   Strings traduzíveis: Marcar todas as strings para tradução
   Locale files: Criar arquivos .pot/.po para tradução
   RTL support: Suporte para idiomas RTL no CSS
   Date/time formats: Usar funções de localização do WordPress
   GAM-AD
   Text domain: Adicionar text domain 'redirectid-ad'
   Strings traduzíveis: Marcar todas as strings para tradução
   Locale files: Criar arquivos .pot/.po para tradução
   Currency formatting: Formatação de moeda localizada
   RTL support: Suporte para idiomas RTL no CSS
6. Documentação
   Ambos os Plugins
   README.md: Documentação completa de instalação e uso
   Inline comments: Comentários PHPDoc em todas as funções/métodos
   Changelog: Manter CHANGELOG.md atualizado
   Hooks documentation: Documentar todos os hooks e filtros disponíveis
   API documentation: Documentar APIs AJAX e endpoints
   Examples: Exemplos de uso de shortcodes e hooks
   Troubleshooting: Guia de solução de problemas comuns
   Akeno Interno
   Block documentation: Documentar atributos e variantes do bloco
   Shortcode examples: Exemplos práticos de uso do shortcode
   Tracking guide: Guia de como funciona o sistema de tracking
   GAM-AD
   Metabox guide: Guia detalhado de uso do metabox
   Token system: Documentação do sistema de tokens /go/
   Scraping guide: Explicação de como funciona o scraping
   Settings guide: Documentação das configurações disponíveis
