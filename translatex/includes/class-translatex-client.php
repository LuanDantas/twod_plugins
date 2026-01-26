<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TranslateX_HtmlChunker {
    private $dom;
    private $chunks = array();
    private $targets = array();
    private $maxChunkLength;

    private static $skipParentTags = array(
        'script','style','noscript','iframe','template','code','pre','kbd','samp'
    );

    private static $translatableAttrByTag = array(
        '*'      => array('title','placeholder','aria-label','aria-description','aria-labelledby','data-translate'),
        'img'    => array('alt'),
        'input'  => array('value','alt'),
        'textarea' => array('placeholder'),
        'button' => array('value'),
        'meta'   => array('content'),
    );

    public static function create($html, $maxChunkLength = 1500) {
        $instance = new self($maxChunkLength);
        if ( ! $instance->initialize_dom($html) ) {
            return null;
        }
        $instance->collect_chunks();
        return $instance;
    }

    private function __construct($maxChunkLength) {
        $this->maxChunkLength = max(200, (int) $maxChunkLength);
    }

    public function has_chunks() {
        return ! empty($this->chunks);
    }

    public function get_chunks() {
        return $this->chunks;
    }

    public function apply_translations($translations) {
        if (count($translations) !== count($this->targets)) {
            return false;
        }

        foreach ($this->targets as $index => $target) {
            $translated = $translations[$index];
            if ($target['type'] === 'text') {
                $target['node']->nodeValue = $translated;
            } elseif ($target['type'] === 'attr') {
                $target['node']->setAttribute($target['attr'], $translated);
            }
        }

        return true;
    }

    public function to_html() {
        if (! $this->dom) {
            return '';
        }
        return $this->dom->saveHTML();
    }

    private function initialize_dom($html) {
        if (!is_string($html) || $html === '') {
            return false;
        }

        if (function_exists('libxml_use_internal_errors')) {
            libxml_use_internal_errors(true);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        $htmlInput = $html;

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($html, 'UTF-8, ISO-8859-1, ASCII', true);
            if ($encoding && strtoupper($encoding) !== 'UTF-8') {
                $htmlInput = mb_convert_encoding($html, 'HTML-ENTITIES', $encoding);
            }
        }

        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $htmlInput, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        if (function_exists('libxml_clear_errors')) {
            libxml_clear_errors();
        }

        if (!$loaded) {
            return false;
        }

        $this->dom = $dom;
        return true;
    }

    private function collect_chunks() {
        if (!$this->dom) {
            return;
        }

        $this->walk($this->dom);
    }

    private function walk($node) {
        if (!$node) {
            return;
        }

        switch ($node->nodeType) {
            case XML_TEXT_NODE:
                $this->handle_text_node($node);
                break;
            case XML_ELEMENT_NODE:
                $this->handle_element_node($node);
                break;
        }

        if ($node->firstChild) {
            $child = $node->firstChild;
            while ($child) {
                $next = $child->nextSibling;
                $this->walk($child);
                $child = $next;
            }
        }
    }

    private function handle_text_node($node) {
        $parent = $node->parentNode;
        if (!$parent || $parent->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag = strtolower($parent->nodeName);
        if (in_array($tag, self::$skipParentTags, true)) {
            return;
        }

        $text = $node->nodeValue;
        if ($text === null) {
            return;
        }

        $trimmed = trim($text, "\r\n\t ");
        if ($trimmed === '') {
            return;
        }

        foreach ($this->split_text($text) as $chunkText) {
            $this->chunks[] = $chunkText;
            $this->targets[] = array(
                'type' => 'text',
                'node' => $node
            );
        }
    }

    private function handle_element_node($node) {
        $tag = strtolower($node->nodeName);

        $attributes = self::$translatableAttrByTag['*'];
        if (isset(self::$translatableAttrByTag[$tag])) {
            $attributes = array_merge($attributes, self::$translatableAttrByTag[$tag]);
        }
        $attributes = array_unique($attributes);

        foreach ($attributes as $attr) {
            if (!$node->hasAttribute($attr)) {
                continue;
            }
            $value = $node->getAttribute($attr);
            if ($value === '') {
                continue;
            }

            if ($tag === 'meta') {
                $nameAttr = strtolower($node->getAttribute('name') . ' ' . $node->getAttribute('property'));
                if ($nameAttr === '') {
                    continue;
                }
                if (
                    strpos($nameAttr, 'description') === false &&
                    strpos($nameAttr, 'title') === false &&
                    strpos($nameAttr, 'og:locale') === false
                ) {
                    continue;
                }
            }

            foreach ($this->split_text($value) as $chunkText) {
                $this->chunks[] = $chunkText;
                $this->targets[] = array(
                    'type' => 'attr',
                    'node' => $node,
                    'attr' => $attr
                );
            }
        }
    }

    private function split_text($text) {
        if (!is_string($text)) {
            return array();
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ($length <= $this->maxChunkLength) {
            return array($text);
        }

        $parts = array();
        $buffer = '';
        $segments = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (empty($segments)) {
            return array($text);
        }

        foreach ($segments as $segment) {
            $segmentLength = function_exists('mb_strlen') ? mb_strlen($segment) : strlen($segment);
            $bufferLength  = function_exists('mb_strlen') ? mb_strlen($buffer) : strlen($buffer);

            if ($buffer !== '' && ($bufferLength + $segmentLength) > $this->maxChunkLength) {
                $parts[] = $buffer;
                $buffer = $segment;
            } else {
                $buffer .= $segment;
            }
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        if (empty($parts)) {
            return array($text);
        }

        return $parts;
    }
}

class TranslateX_Client {
    const MAX_TEXTS_PER_REQUEST = 90;

    private $api_key          = "AIzaTXXtgJFbn3a7UWJunm0H5QFDznnrZ2ZA4hN";
    private $api_url_translate= "https://api.translatex.com/translate";
    private $api_url_detect   = "https://api.translatex.com/detect";
    private $max_text_batch   = 120;
    private static $detect_cache = array();

    public static $last_status   = null;
    public static $last_response = null;

    /**
     * Fluxo robusto de tradução:
     * - Decide sl (source) com base em /detect e na heurística do HTML original;
     * - Tenta traduzir (sl decidido ou 'auto');
     * - Se alvo=pt e ainda “parecer espanhol”, força retry sl=es;
     * - Pós-processa o HTML para ajustar lang/og:locale ao alvo.
     */
    public function translate($html, $targetLang, $chunkPayload = null) {
        $targetLang = $this->normalize_target($targetLang); // por segurança

        $looks_spanish = $this->looks_spanish($html);

        // 1) Decide idioma de origem com heurísticas antes de consultar a API
        $sourceLang = 'auto';
        $detected   = null;

        if ($targetLang === 'pt') {
            if ($looks_spanish) {
                $sourceLang = 'es';
            } else {
                $detected = $this->detect_language($html);
                if ($detected === 'es') {
                    $sourceLang = 'es';
                }
            }
        } elseif ($looks_spanish) {
            // Conteúdo claramente em espanhol e destino não é pt: ajuda a API setando sl=es.
            $sourceLang = 'es';
        } else {
            $detected = $this->detect_language($html);
            if ($detected && $detected !== $targetLang) {
                $sourceLang = $detected;
            }
        }

        $useChunks = $this->is_valid_chunk_payload($chunkPayload);
        if ($useChunks && function_exists('translatex_log')) {
            translatex_log(sprintf('TranslateX_Client: tentativa via blocos (%d segmentos).', count($chunkPayload['texts'])));
        }
        $result = $useChunks
            ? $this->translate_text_in_batches($chunkPayload['texts'], $targetLang, $sourceLang)
            : $this->do_translate_html($html, $targetLang, $sourceLang);

        if ((!$result || !is_array($result)) && $useChunks) {
            if (function_exists('translatex_log')) {
                translatex_log('TranslateX_Client: falha na requisição em blocos, fazendo fallback para HTML completo.');
            }
            if ($this->should_attempt_html_fallback(self::$last_status)) {
                $useChunks = false;
                $result = $this->do_translate_html($html, $targetLang, $sourceLang);
            } else {
                if (function_exists('translatex_log')) {
                    translatex_log('TranslateX_Client: fallback HTML suprimido devido a falha crítica nos blocos.');
                }
                return false;
            }
        }

        if ($result && is_array($result)) {
            [$translatedPayload, $det] = $result;
            $translated = null;

            if ($useChunks) {
                if (!function_exists('translatex_apply_chunk_translations')) {
                    self::$last_status   = 'CHUNK_REASSEMBLY_MISSING';
                    self::$last_response = 'Função translatex_apply_chunk_translations() indisponível';
                    return false;
                }

                $rebuilt = translatex_apply_chunk_translations($chunkPayload, $translatedPayload);
                if ($rebuilt === false) {
                    self::$last_status   = 'CHUNK_REASSEMBLY_ERROR';
                    self::$last_response = 'Falha ao reconstruir HTML a partir dos blocos traduzidos';
                    if (function_exists('translatex_log')) {
                        translatex_log('TranslateX_Client: reconstrução dos blocos falhou, fallback para HTML completo.');
                    }
                    $useChunks = false;
                    $fallback = $this->do_translate_html($html, $targetLang, $sourceLang);
                    if (!$fallback || !is_array($fallback)) {
                        return false;
                    }
                    [$translatedPayload, $det] = $fallback;
                } else {
                    $translated = $rebuilt;
                }
            }

            if (!$useChunks) {
                $translated = $translatedPayload;
            }

            // 4) Retry: se alvo=pt e ainda parece espanhol, força sl=es
            if ($targetLang === 'pt' && $this->looks_spanish($translated)) {
                if ($useChunks) {
                    $retry = $this->translate_text_in_batches($chunkPayload['texts'], $targetLang, 'es');
                    if ($retry && is_array($retry)) {
                        $rebuiltRetry = translatex_apply_chunk_translations($chunkPayload, $retry[0]);
                        if ($rebuiltRetry !== false) {
                            $translated = $rebuiltRetry;
                        }
                    }
                } else {
                    $retry = $this->do_translate_html($html, $targetLang, 'es');
                    if ($retry && is_array($retry)) {
                        $translated = $retry[0];
                    }
                }
            }

            // 5) Pós-processamento (ajuste de lang/meta)
            $translated = $this->postprocess_html($translated, $targetLang);
            return $translated;
        }

        // Falha → devolve original
        return false;
    }

    /**
     * Traduz conjunto de textos únicos, preservando as chaves fornecidas.
     *
     * @param array       $texts        Array associativo (chave => texto original).
     * @param string      $targetLang   Idioma alvo.
     * @param string|null $originalHtml HTML original (para heurísticas de detecção).
     * @return array|false              Traduções com mesmas chaves ou false em caso de falha.
     */
    public function translate_texts( array $texts, $targetLang, $originalHtml = null ) {
        if ( empty( $texts ) ) {
            return array();
        }

        $normalized_target = $this->normalize_target( $targetLang );

        $ordered_keys  = array();
        $ordered_texts = array();
        foreach ( $texts as $key => $text ) {
            if ( ! is_scalar( $text ) ) {
                continue;
            }
            $ordered_keys[]  = $key;
            $ordered_texts[] = (string) $text;
        }

        if ( empty( $ordered_texts ) ) {
            return array();
        }

        $sourceLang = 'auto';
        $detected   = null;
        $looks_spanish = $originalHtml ? $this->looks_spanish( $originalHtml ) : false;

        if ( $normalized_target === 'pt' ) {
            if ( $looks_spanish ) {
                $sourceLang = 'es';
            } else if ( $originalHtml !== null ) {
                $detected = $this->detect_language( $originalHtml );
                if ( $detected === 'es' ) {
                    $sourceLang = 'es';
                } elseif ( ! empty( $detected ) && $detected !== $normalized_target ) {
                    $sourceLang = $detected;
                }
            }
        } elseif ( $looks_spanish ) {
            $sourceLang = 'es';
        } elseif ( $originalHtml !== null ) {
            $detected = $this->detect_language( $originalHtml );
            if ( ! empty( $detected ) && $detected !== $normalized_target ) {
                $sourceLang = $detected;
            }
        }

        $result = $this->translate_text_in_batches( $ordered_texts, $normalized_target, $sourceLang );
        if ( ! $result || ! is_array( $result ) ) {
            return false;
        }

        $merged_translations = isset( $result[0] ) ? $result[0] : array();

        if ( ! is_array( $merged_translations ) || count( $merged_translations ) !== count( $ordered_keys ) ) {
            self::$last_status   = 'CHUNK_BATCH_MISMATCH';
            self::$last_response = 'translate_texts(): mismatch entre textos enviados e retornados';
            return false;
        }

        if ( $normalized_target === 'pt' && $this->looks_spanish( implode( ' ', $merged_translations ) ) ) {
            $retry = $this->translate_text_in_batches( $ordered_texts, $normalized_target, 'es' );
            if ( $retry && is_array( $retry ) && is_array( $retry[0] ) && count( $retry[0] ) === count( $ordered_keys ) ) {
                $merged_translations = $retry[0];
            }
        }

        $translated_by_key = array();
        foreach ( $ordered_keys as $index => $key ) {
            $translated_by_key[ $key ] = isset( $merged_translations[ $index ] ) ? $merged_translations[ $index ] : '';
        }

        return $translated_by_key;
    }

    /** Chamada de tradução (HTML). Retorna [htmlTraduzido, detected_lang|null] ou false. */
    private function do_translate_html($html, $targetLang, $sourceLang = 'auto') {
        $request_start = microtime(true);
        $url = $this->api_url_translate
            . "?sl=" . urlencode($sourceLang)
            . "&tl=" . urlencode($targetLang)
            . "&key=" . $this->api_key;

        $args = array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => http_build_query(array('html' => $html)),
            'timeout' => 45
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            self::$last_status   = "ERROR";
            self::$last_response = $response->get_error_message();
            if (function_exists('translatex_log')) {
                translatex_log(sprintf(
                    'TranslateX_Client::do_translate_html falhou: %s',
                    self::$last_response
                ));
            }
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $elapsed_ms = round((microtime(true) - $request_start) * 1000, 2);

        self::$last_status   = $status;
        self::$last_response = $body;

        if (function_exists('translatex_log')) {
            translatex_log(sprintf(
                'TranslateX_Client::do_translate_html status=%s time_ms=%.2f len=%d',
                $status,
                $elapsed_ms,
                strlen($html)
            ));
        }

        if ($status === 200 && !empty($body)) {
            $data = json_decode($body, true);
            if (isset($data['translation'])) {
                $det = isset($data['detected_lang']) ? $data['detected_lang'] : null;
                return array($data['translation'], $det);
            }
        }

        if (function_exists('translatex_log')) {
            translatex_log(sprintf(
                'TranslateX_Client::do_translate_html resposta inválida status=%s body_snippet=%s',
                $status,
                is_string($body) ? substr($body, 0, 400) : ''
            ));
        }

        return false;
    }

    private function translate_text_in_batches($texts, $targetLang, $sourceLang = 'auto', $forcedBatchSize = null, $depth = 0) {
        if (!is_array($texts) || empty($texts)) {
            return false;
        }

        if ($depth > 4) {
            if (function_exists('translatex_log')) {
                translatex_log('TranslateX_Client::translate_text_in_batches abortado (profundidade máxima atingida).');
            }
            return false;
        }

        $batch_size = $forcedBatchSize !== null
            ? (int) $forcedBatchSize
            : (int) apply_filters('translatex_max_text_batch', $this->max_text_batch, $texts, $targetLang, $sourceLang);

        if ($batch_size < 1) {
            $batch_size = $this->max_text_batch;
        }
        $batch_size = max(1, min($batch_size, self::MAX_TEXTS_PER_REQUEST));

        $total = count($texts);
        $offset = 0;
        $merged = array();
        $detected = null;
        $batch_number = 0;

        while ($offset < $total) {
            $batch = array_slice($texts, $offset, $batch_size);
            $batch_number++;

            if (function_exists('translatex_log')) {
                translatex_log(sprintf(
                    'TranslateX_Client::translate_text_in_batches batch=%d size=%d/%d',
                    $batch_number,
                    count($batch),
                    $total
                ));
            }

            $result = $this->do_translate_text($batch, $targetLang, $sourceLang);
            if (!$result || !is_array($result)) {
                return false;
            }

            [$translations, $det] = $result;
            $expected = count($batch);
            $received = is_array($translations) ? count($translations) : 0;

            if (!is_array($translations) || $received !== $expected) {
                if ($expected > 1) {
                    $candidate = $received > 0 ? $received : max(1, (int) floor($expected / 2));
                    $new_size  = max(1, min($candidate, self::MAX_TEXTS_PER_REQUEST, $batch_size - 1));

                    if ($new_size > 0 && $new_size < $batch_size) {
                        if (function_exists('translatex_log')) {
                            translatex_log(sprintf(
                                'TranslateX_Client::translate_text_in_batches ajuste batch de %d para %d (recebidas %d).',
                                $batch_size,
                                $new_size,
                                $received
                            ));
                        }
                        return $this->translate_text_in_batches($texts, $targetLang, $sourceLang, $new_size, $depth + 1);
                    }
                }

                self::$last_status   = 'CHUNK_BATCH_MISMATCH';
                self::$last_response = sprintf(
                    'Esperado %d traduções, recebeu %d (batch %d offset %d)',
                    count($batch),
                    $received,
                    $batch_number,
                    $offset
                );
                if (function_exists('translatex_log')) {
                    translatex_log('TranslateX_Client::translate_text_in_batches erro: ' . self::$last_response);
                }
                return false;
            }

            $merged = array_merge($merged, $translations);
            if (!$detected && !empty($det)) {
                $detected = $det;
            }

            $offset += count($batch);
        }

        return array($merged, $detected);
    }

    private function should_attempt_html_fallback($status) {
        if ($status === 'CHUNK_REASSEMBLY_ERROR' || $status === 'CHUNK_REASSEMBLY_MISSING') {
            return true;
        }
        if ($status === 'ERROR' || $status === 'CHUNK_BATCH_MISMATCH') {
            return false;
        }
        if (is_numeric($status)) {
            $code = (int) $status;
            if ($code === 413 || $code === 429 || $code >= 500) {
                return false;
            }
        }
        return true;
    }

    /** Chamada de tradução em blocos de texto. Retorna [array traduções, detected_lang|null] ou false. */
    private function do_translate_text($texts, $targetLang, $sourceLang = 'auto') {
        if (!is_array($texts) || empty($texts)) {
            return false;
        }

        $request_start = microtime(true);
        $total_chars = 0;
        foreach ($texts as $text) {
            $total_chars += function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        }

        $url = $this->api_url_translate
            . "?sl=" . urlencode($sourceLang)
            . "&tl=" . urlencode($targetLang)
            . "&key=" . $this->api_key;

        $body_parts = array();
        foreach ($texts as $text) {
            $body_parts[] = 'text=' . rawurlencode($text);
        }
        $body = implode('&', $body_parts);

        $args = array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => $body,
            'timeout' => 45
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            self::$last_status   = "ERROR";
            self::$last_response = $response->get_error_message();
            if (function_exists('translatex_log')) {
                translatex_log(sprintf(
                    'TranslateX_Client::do_translate_text falhou: %s (count=%d chars=%d payload_bytes=%d)',
                    self::$last_response,
                    count($texts),
                    $total_chars,
                    strlen($body)
                ));
            }
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $elapsed_ms = round((microtime(true) - $request_start) * 1000, 2);

        self::$last_status   = $status;
        self::$last_response = $body;

        if (function_exists('translatex_log')) {
            translatex_log(sprintf(
                'TranslateX_Client::do_translate_text status=%s time_ms=%.2f count=%d chars=%d payload_bytes=%d',
                $status,
                $elapsed_ms,
                count($texts),
                $total_chars,
                strlen($body)
            ));
        }

        if ($status === 200 && !empty($body)) {
            $data = json_decode($body, true);
            if (isset($data['translation'])) {
                $translations = $data['translation'];
                if (!is_array($translations)) {
                    $translations = array($translations);
                }
                $det = isset($data['detected_lang']) ? $data['detected_lang'] : null;
                return array($translations, $det);
            }
        }

        if (function_exists('translatex_log')) {
            translatex_log(sprintf(
                'TranslateX_Client::do_translate_text resposta inválida status=%s body_snippet=%s',
                $status,
                is_string($body) ? substr($body, 0, 400) : ''
            ));
        }

        return false;
    }

    private function is_valid_chunk_payload($payload) {
        if (!is_array($payload)) {
            return false;
        }
        if (empty($payload['texts']) || !is_array($payload['texts'])) {
            return false;
        }
        if (empty($payload['chunks']) || !is_array($payload['chunks'])) {
            return false;
        }
        if (empty($payload['tokenized_html']) || !is_string($payload['tokenized_html'])) {
            return false;
        }
        return true;
    }

    /**
     * /detect: retorna 'es','en','pt', etc., ou null.
     * Usa um trecho de texto limpo do HTML original para reduzir ruído.
     */
    private function detect_language($html) {
        $text = $this->extract_text_sample($html, 2000);
        if ($text === '') return null;

        $hash = md5($text);
        if (isset(self::$detect_cache[$hash])) {
            return self::$detect_cache[$hash];
        }

        $url  = $this->api_url_detect . "?key=" . $this->api_key;
        $args = array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => http_build_query(array('text' => $text)),
            'timeout' => 25
        );

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) return null;

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        if ($status !== 200 || empty($body)) {
            self::$detect_cache[$hash] = null;
            return null;
        }

        $data = json_decode($body, true);
        $detected = isset($data['detections'][0]['language']) ? $data['detections'][0]['language'] : null;
        self::$detect_cache[$hash] = $detected;
        return $detected;
    }

    /** Extrai texto limpo do HTML (sem scripts/styles), limitado a maxLen. */
    private function extract_text_sample($html, $maxLen = 2000) {
        if (!is_string($html) || $html === '') return '';
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $clean = wp_strip_all_tags($clean, true);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        if (function_exists('mb_substr')) {
            if (mb_strlen($clean) > $maxLen) $clean = mb_substr($clean, 0, $maxLen);
        } else {
            if (strlen($clean) > $maxLen) $clean = substr($clean, 0, $maxLen);
        }
        return $clean;
    }

    /** Heurística: o HTML “parece espanhol”? */
    private function looks_spanish($html) {
        if (!is_string($html) || $html === '') return false;

        // Sinais estruturais
        if (stripos($html, 'lang="es"') !== false) return true;
        if (stripos($html, "content=\"es_") !== false && stripos($html, "og:locale") !== false) return true;

        // Amostra de texto
        $sample = strtolower(' ' . wp_strip_all_tags($html, true) . ' ');
        $tokens = array(' el ', ' la ', ' de ', ' en ', ' y ', ' los ', ' las ', ' para ', ' con ');
        $hits = 0;
        foreach ($tokens as $t) {
            if (strpos($sample, $t) !== false) $hits++;
            if ($hits >= 4) return true;
        }
        return false;
    }

    /** Ajustes finais de lang/meta para combinar com o alvo. */
    private function postprocess_html($html, $targetLang) {
        if (!is_string($html) || $html === '') return $html;

        // Ajuste de <html lang="...">
        $html = preg_replace(
            '#(<html\b[^>]*\blang=)["\']?[a-zA-Z\-_]+["\']?#i',
            '$1"' . $this->html_lang_for($targetLang) . '"',
            $html,
            1
        );

        // Ajuste de og:locale (formato comum pt_BR, es_ES, en_US etc.)
        $locale = $this->og_locale_for($targetLang);
        if ($locale) {
            // se existir a meta, troca; se não, mantém como está
            $html = preg_replace(
                '#(<meta[^>]+property=["\']og:locale["\'][^>]+content=["\'])[a-zA-Z_\-]+(["\'][^>]*>)#i',
                '$1' . $locale . '$2',
                $html,
                1
            );
        }

        return $html;
    }

    /** Conversão simples de 'pt','es','en','zh-CN','zh-TW','iw' → atributo lang HTML. */
    private function html_lang_for($code) {
        switch ($code) {
            case 'zh-CN': return 'zh-CN';
            case 'zh-TW': return 'zh-TW';
            case 'iw':    return 'he';     // atributo lang moderno
            default:      return $code;    // 'pt','es','en','fr','ko', etc.
        }
    }

    /** Conversão para og:locale comum. */
    private function og_locale_for($code) {
        switch ($code) {
            case 'pt':    return 'pt';
            case 'es':    return 'es';
            case 'en':    return 'en';
            case 'fr':    return 'fr';
            case 'de':    return 'de';
            case 'it':    return 'it';
            case 'nl':    return 'nl';
            case 'ru':    return 'ru';
            case 'ja':    return 'ja';
            case 'ko':    return 'ko';
            case 'iw':    return 'he';
            case 'zh-CN': return 'zh-CN';
            case 'zh-TW': return 'zh-TW';
            default:      return null;
        }
    }

    /** Segurança: garante que o tl seja um dos códigos do JSON (ou mantém como veio). */
    private function normalize_target($lang) {
        if (!is_string($lang) || $lang === '') return $lang;
        $l = trim($lang);
        // conjunto suportado (exato como JSON)
        $supported = array(
            'ro','ar','no','iw','vi','ko','bg','cs','hr','th','lt','uk','fi','hi','hu','bn','sk','sl','id',
            'en','fr','es','de','it','nl','ru','pt','ja','tr','pl','sv','da','zh-CN','zh-TW','el'
        );
        return in_array($l, $supported, true) ? $l : $l;
    }
}

