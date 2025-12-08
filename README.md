Plugin 1: Akeno Interno
Funcionalidades principais
Bloco Gutenberg para botões de navegação interna
Até 3 botões por bloco
13 variantes de estilo (primary, secondary, outline, inverse, cta, pill, gradient, shadow, soft, flat, accent, horizontal, large)
Tracking de origem via query params (?tp=new&src=ID)
Resolução de URLs: ID, slug ou URL completa
Fallback de texto: usa título do post destino ou atual
Emojis automáticos baseados em palavras-chave
Integração com Yoast SEO para sugestões
API de sugestões do Google
Shortcode [akeno_buttons]
Compatibilidade com bloco legado twod/redirect-buttons
Arquitetura técnica
Bloco dinâmico (ServerSideRender)
AJAX para buscar informações de posts
Sistema de tracking com precedência: src individual > src da URL > ID do post atual
Helpers para resolução de URLs e detecção de links internos
CSS responsivo com variáveis CSS
Pontos fortes
Interface de edição intuitiva
Múltiplas variantes de estilo
Tracking de origem
Resolução de URLs flexível
Possíveis melhorias/problemas
Validação: não valida se o post destino existe antes de renderizar
Performance: múltiplas chamadas AJAX podem ser lentas
Segurança: nonce criado uma vez no init; melhor gerar por requisição
Acessibilidade: falta aria-label nos botões
Cache: sem cache de resolução de URLs
Error handling: falta tratamento de erros nas chamadas AJAX
Internacionalização: texto hardcoded em espanhol/português

# ----------------------------------------------

Plugin 2: GAM-AD (RedirectID AD)
Funcionalidades principais
Metabox para configurar múltiplos apps por post
Cards de apps com informações de Google Play e App Store
Inserção automática em parágrafos específicos
Sistema de tokens temporários (/go/<token>)
Delay configurável para redirecionamento (ajuda interstitials)
Cache de metadados (12 horas)
Scraping de Google Play e iTunes API
Detecção automática de cor primária do tema
Shortcode manual [rid_app_card]
Drag & drop para reordenar apps no metabox
Arquitetura técnica
Classe singleton RedirectID_AD
Rewrite rules para endpoint /go/<token>
Transients para tokens e cache de metadados
Parsing de HTML (Google Play) e JSON (iTunes)
Inserção de conteúdo via the_content filter
Contagem de parágrafos via wpautop
Pontos fortes
Interface admin funcional
Cache de metadados
Sistema de tokens temporários
Delay configurável para interstitials
Detecção automática de cor do tema
Possíveis melhorias/problemas
Scraping frágil: regex pode quebrar com mudanças no HTML
Performance: scraping síncrono pode travar o admin
Error handling: falta tratamento quando APIs falham
Validação: não valida URLs antes de fazer scraping
Rate limiting: sem controle de rate limit nas APIs externas
Segurança: tokens podem ser bruteforced (TTL curto ajuda)
Contagem de parágrafos: wpautop pode não refletir o conteúdo real
Internacionalização: textos hardcoded
Fallback: se scraping falhar, card fica vazio
Preço: formato fixo em dólar ($X), não localizado

Comparação geral
Aspecto Akeno Interno GAM-AD
Complexidade Média Alta
Dependências externas Google Suggest API Google Play + iTunes
Performance Boa Pode melhorar (scraping)
Manutenibilidade Boa Média (scraping frágil)
Segurança Boa Boa
UX Admin Excelente Boa
Código Limpo Organizado
Recomendações gerais
Internacionalização: usar \_\_() e \_e() para textos
Validação: validar dados antes de processar
Error handling: tratamento de erros consistente
Cache: melhorar estratégias de cache onde aplicável
Performance: otimizar chamadas externas (async/background)
Testes: adicionar testes unitários
Documentação: documentar hooks e filtros

Confirmação
Análise concluída. Os plugins estão funcionais, com pontos de melhoria identificados.
Próximos passos sugeridos:
Correções de bugs e melhorias de segurança
Otimizações de performance
Novas funcionalidades
Refatoração de código
Internacionalização
Documentação
Qual direção você prefere seguir?
