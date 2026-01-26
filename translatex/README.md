# Documentação — Integração de Tradução com TranslateX

## 1. Objetivo

Fornecer tradução dinâmica de páginas WordPress utilizando a API **TranslateX**, substituindo soluções caras como o GTranslate.
A implementação:

- Mantém URLs amigáveis com prefixo de idioma (`/en`, `/es`, `/pt`, etc.);
- Preserva a navegação no idioma escolhido;
- Usa cache dedicado (`wp_translatex_cache`) que permanece válido enquanto o conteúdo original não muda;
- Detecta alterações de conteúdo por meio de uma _fingerprint_ e regenera traduções somente quando necessário;
- Permite acompanhar e gerenciar o cache direto pelo painel do WordPress.

---

## 2. Estrutura do Plugin

Crie uma pasta no diretório `wp-content/plugins`:

```
translatex-integration/
 ├── translatex-integration.php
 └── includes/
      ├── class-translatex-client.php
      └── class-translatex-cache.php
```

### `translatex-integration.php`

Arquivo principal do plugin, responsável por:

- Configurar **rewrite rules** para URLs `/xx/...`;
- Detectar idioma e iniciar buffer de tradução;
- Preservar idioma em todos os links do site (PHP + fallback em JS);
- Gerenciar cache (armazenamento + limpeza via cron + painel admin).

### `includes/class-translatex-client.php`

Cliente PHP responsável por:

- Fazer a chamada HTTP para a API **TranslateX**;
- Enviar HTML completo para tradução;
- Retornar o HTML traduzido.

### `includes/class-translatex-cache.php`

Camada de cache responsável por:

- Persistir traduções por URL/idioma em tabela própria (`{prefix}translatex_cache`);
- Gerenciar hits, horários de geração e _fingerprints_ por URL/idioma;
- Expor utilitários para leitura, gravação e limpeza programada.

---

## 3. Instalação

1. Copie os arquivos para `wp-content/plugins/translatex-integration`.
2. Ative o plugin no painel WordPress (Plugins → Ativar).
3. Vá em **Configurações → Links Permanentes → Salvar alterações** para atualizar as regras de URL.
4. Teste as rotas:

   - `https://seudominio.com/en`
   - `https://seudominio.com/es`
   - `https://seudominio.com/pt`

---

## 4. Funcionamento

### 4.1 Tradução

- O plugin intercepta o HTML final da página via `ob_start()`.
- Envia esse HTML para o endpoint `/translate` da API TranslateX:

  ```
  POST https://api.translatex.com/translate?sl=auto&tl={lang}&key={API_KEY}
  ```

- Recebe a versão traduzida e a entrega ao usuário.

### 4.2 Cache

- Cada tradução é associada à combinação **URL + idioma**.
- No primeiro acesso, o HTML é traduzido e salvo na tabela `{prefix}translatex_cache` junto com uma _fingerprint_ do conteúdo original.
- Enquanto a _fingerprint_ calculada no carregamento corresponder à registrada, a resposta vem diretamente do cache (com uso opcional do object cache do WordPress).
- Ao detectar alteração de conteúdo (HTML diferente), a tradução é regenerada automaticamente e o cache é sobrescrito. Uma rotina diária (`translatex_purge_cache`) remove apenas entradas antigas sem fingerprint (legado).
- Adicione `?nocache=1` à URL para forçar a regeneração manual de uma página específica.

### 4.3 URLs Amigáveis

- `/en` → traduz a Home para inglês.
- `/es/slug-da-pagina` → traduz a página `slug-da-pagina` para espanhol.
- Preservação automática em todos os links internos.

---

## 5. Configuração da API Key

No arquivo `class-translatex-client.php`, substitua:

```php
private $api_key = "SUA_API_KEY_AQUI";
```

pela chave fornecida pela TranslateX.

---

## 6. Painel Administrativo

No painel do WP, em **Configurações → TranslateX Cache**:

- Visualize total de itens, última geração e próxima limpeza agendada;
- Limpe todo o cache (banco + memória) com um clique;
- Remova entradas sem _fingerprint_ (legado) ou limpe o cache em memória (object cache);
- Exclua entradas específicas informando idioma e URL.

---

## 7. Requisitos

- WordPress 5.5+
- PHP 7.4+
- Permissão para usar **cron interno do WP** (ou configurar cron no servidor) para executar a limpeza diária de entradas legadas (opcional, mas recomendado).

---

## 8. Boas práticas e extensões

- **Cloudflare ou outro cache reverso** pode ser usado em conjunto para reduzir ainda mais chamadas.
- Pode-se criar opção no painel para configurar a **API Key** via interface, evitando editar código.
- É possível integrar lógica para **detectar idioma do navegador** e redirecionar automaticamente para `/xx`.

---

## 9. Limitações

- Cada novo conteúdo acessado em um idioma ainda não traduzido gera **uma chamada à API** (primeira visita).
- Conteúdo altamente dinâmico (ex.: páginas com dados em tempo real) pode não ser traduzido de forma previsível.
- O cache é feito em nível de HTML completo — não recomendado para páginas de login, carrinhos de compras ou áreas com dados sensíveis.

---

## 10. Exemplos de Uso

- `https://meusite.com/en` → versão em inglês da Home.
- `https://meusite.com/es/noticias/mercado` → versão em espanhol da página `noticias/mercado`.
- Usuário clica em qualquer link → o idioma escolhido é preservado.

---
