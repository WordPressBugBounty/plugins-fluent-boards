<?php

namespace FluentBoards\App\Services;

use FluentBoards\Framework\Support\Arr;

/**
 * AI writing assistant service.
 *
 * Mirrors the FluentCRM AI implementation so the Fluent suite stays in sync:
 * credentials are shared across the suite via the global `_fluent_ai_creds`
 * option, while Fluent Boards keeps its own writing preferences.
 *
 * Providers supported: OpenAI, Anthropic (Claude), Google Gemini and the
 * WordPress-native AI client. All provider calls are blocking wp_remote_post
 * requests (no streaming).
 */
class AiService
{
    /**
     * Suite-wide credentials option (shared with FluentCRM).
     * @var string
     */
    private $credentialsOptionKey = '_fluent_ai_creds';

    /**
     * Fluent Boards AI preferences key (stored in fbs_meta).
     * @var string
     */
    private $settingsOptionKey = 'ai_features_settings';

    /**
     * Pre-release key name, read as a fallback so an existing toggle survives.
     * @var string
     */
    private $legacySettingsOptionKey = 'ai_writing_settings';

    private $providerModels = [
        'wordpress' => ['wordpress'],
        'open_ai'   => ['auto', 'gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-4.1', 'gpt-4o', 'gpt-4o-mini'],
        'claude'    => ['auto', 'claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001', 'claude-opus-4-6'],
        'gemini'    => ['auto', 'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3-flash-preview', 'gemini-3.1-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'],
    ];

    private $autoProviderModels = [
        'open_ai'   => 'gpt-5.4',
        'claude'    => 'claude-sonnet-4-6',
        'gemini'    => 'gemini-3.5-flash',
        'wordpress' => 'wordpress',
    ];

    /**
     * Actions the in-editor assistant can request.
     * @var array
     */
    private $validActions = ['write', 'improve', 'summarize', 'shorten', 'expand', 'format', 'fix_grammar', 'custom'];

    /**
     * Prompt size budgets (characters). Provider calls are blocking, so an
     * unbounded payload would hold a PHP worker for the full timeout, blow past
     * the model context limit, and burn tokens.
     */
    const MAX_CONTENT_CHARS = 12000;
    const MAX_PROMPT_CHARS  = 2000;
    const MAX_CONTEXT_CHARS = 12000;

    /**
     * Whether the current site can use the WordPress-native AI client.
     */
    public function hasWordPressAi()
    {
        global $wp_version;
        return (intval(explode('.', $wp_version)[0]) >= 7);
    }

    /**
     * Saved settings with the API key masked for frontend display.
     */
    public function getDisplaySettings()
    {
        $settings = $this->getSavedSettings();

        if (!empty($settings['api_key'])) {
            $settings['api_key'] = '****' . substr($settings['api_key'], -4);
        }

        return $settings;
    }

    /**
     * Persist AI settings. Returns an array on success or WP_Error on failure.
     *
     * @param array $data
     * @return array|\WP_Error
     */
    public function saveSettings($data)
    {
        $isEnabled    = sanitize_text_field(Arr::get($data, 'is_enabled', 'no')) === 'yes' ? 'yes' : 'no';
        $provider     = $this->normalizeProvider(sanitize_text_field(Arr::get($data, 'provider', '')));
        $model        = sanitize_text_field(Arr::get($data, 'model', 'auto'));
        $apiKey       = sanitize_text_field(Arr::get($data, 'api_key', ''));
        $customPrompt = sanitize_textarea_field(Arr::get($data, 'custom_prompt', ''));

        if ($provider === 'wordpress' && !$this->hasWordPressAi()) {
            return new \WP_Error('unsupported', __('WordPress AI is only supported in WordPress 7.0 or higher.', 'fluent-boards'));
        }

        if ($provider && !in_array($provider, array_keys($this->providerModels), true)) {
            return new \WP_Error('invalid_provider', __('Invalid AI provider selected.', 'fluent-boards'));
        }

        /*
         * The credentials option is shared suite-wide — FluentCRM and other Fluent
         * plugins read and write the same `_fluent_ai_creds` key. Fluent Boards must
         * NEVER erase it: an empty or masked submission always keeps what is already
         * stored, and each field only moves forward to a real new value. Clearing the
         * shared key is intentionally not possible from this screen, because doing so
         * would silently break AI in every other Fluent plugin on the site.
         */
        $existing         = $this->getSavedCredentials();
        $existingKey      = (string) Arr::get($existing, 'api_key', '');
        $existingProvider = (string) Arr::get($existing, 'provider', '');
        $existingModel    = (string) Arr::get($existing, 'model', '');

        // Only a real, non-masked value replaces the stored key.
        $plainApiKey = $existingKey;
        if ($apiKey !== '' && strpos($apiKey, '****') !== 0) {
            $plainApiKey = $apiKey;
        }

        // A blank submission keeps the stored provider/model rather than blanking them.
        if (!$provider) {
            $provider = $existingProvider;
        }
        if (!$model) {
            $model = $existingModel ?: 'auto';
        }

        // Never persist a model the selected provider does not support, otherwise
        // every later generation resolves it as-is and keeps calling the provider
        // with an invalid model.
        if ($provider && !in_array($model, Arr::get($this->providerModels, $provider, []), true)) {
            return new \WP_Error('invalid_model', __('Invalid AI model selected for this provider.', 'fluent-boards'));
        }

        $credentials = [
            'provider'   => $provider,
            'model'      => $model,
            'api_key'    => $plainApiKey,
            // Keep whichever Fluent plugin first created the shared credentials.
            'created_by' => sanitize_text_field(Arr::get($existing, 'created_by', '')) ?: 'fluent_boards',
        ];

        // Final guard: never write a credential set that would wipe an existing key.
        if ($existingKey !== '' && $credentials['api_key'] === '') {
            $credentials['api_key'] = $existingKey;
        }

        // Only touch the shared option when something actually changed.
        if ($credentials['provider'] !== $existingProvider
            || $credentials['model'] !== $existingModel
            || $credentials['api_key'] !== $existingKey
        ) {
            update_option($this->credentialsOptionKey, $credentials, false);
        }
        fluent_boards_update_option($this->settingsOptionKey, [
            'is_enabled'    => $isEnabled,
            'custom_prompt' => $customPrompt,
        ]);

        return ['message' => __('AI configuration saved successfully.', 'fluent-boards')];
    }

    /**
     * Model dropdown options for a provider.
     *
     * @param string $provider
     * @return array|\WP_Error
     */
    public function getModelOptions($provider)
    {
        $provider = $this->normalizeProvider($provider);

        if (!$provider || !in_array($provider, array_keys($this->providerModels), true)) {
            return new \WP_Error('invalid_provider', __('Invalid AI provider selected.', 'fluent-boards'));
        }

        $models = [];
        foreach (Arr::get($this->providerModels, $provider, []) as $model) {
            $models[] = [
                'value' => $model,
                'label' => $model === 'auto' ? __('Auto', 'fluent-boards') : $model,
            ];
        }

        return $models;
    }

    /**
     * Round-trip a small probe prompt to validate credentials.
     *
     * @param array $data provider/model/api_key
     * @return true|\WP_Error
     */
    public function testConnection($data)
    {
        $provider = $this->normalizeProvider(sanitize_text_field(Arr::get($data, 'provider', '')));
        $model    = sanitize_text_field(Arr::get($data, 'model', 'auto'));
        $apiKey   = sanitize_text_field(Arr::get($data, 'api_key', ''));

        if (!$provider || !$model) {
            return new \WP_Error('missing_config', __('Please select a provider and model first.', 'fluent-boards'));
        }

        if (!in_array($provider, array_keys($this->providerModels), true)) {
            return new \WP_Error('invalid_provider', __('Invalid AI provider selected.', 'fluent-boards'));
        }

        // Use the stored key when the field still shows the masked value.
        if (!$apiKey || strpos($apiKey, '****') === 0) {
            $apiKey = Arr::get($this->getSavedSettings(), 'api_key', '');
        }

        if ($provider !== 'wordpress' && !$apiKey) {
            return new \WP_Error('missing_api_key', __('Please enter an API key.', 'fluent-boards'));
        }

        $resolvedModel = $this->resolveModel($provider, $model);
        if (!$resolvedModel) {
            return new \WP_Error('missing_model', __('AI model is not configured. Please set it in Settings.', 'fluent-boards'));
        }

        $result = $this->callProviderApi($provider, $resolvedModel, $apiKey, 'Say "Connection successful" in exactly two words.', '', 15);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Generate/transform text for the task description editor.
     *
     * @param string $action  one of $validActions
     * @param string $content selected text or existing description
     * @param string $tone    optional tone hint
     * @param string $prompt  user prompt for "write"/"custom" actions
     * @param array  $context optional task context (title, board name)
     * @return string|\WP_Error markdown
     */
    public function generate($action, $content, $tone = '', $prompt = '', $context = [])
    {
        $action  = sanitize_text_field($action);
        // Bound the payload before it reaches the blocking provider request.
        $content = $this->truncate((string) $content, self::MAX_CONTENT_CHARS);
        $prompt  = $this->truncate(sanitize_textarea_field($prompt), self::MAX_PROMPT_CHARS);

        if (!in_array($action, $this->validActions, true)) {
            return new \WP_Error('invalid_action', __('Invalid action specified.', 'fluent-boards'));
        }

        $needsPromptActions = ['write', 'custom'];
        if (in_array($action, $needsPromptActions, true)) {
            if (empty($prompt) && empty($content)) {
                return new \WP_Error('no_input', __('Please provide a prompt or select some text.', 'fluent-boards'));
            }
        } elseif (empty($content)) {
            return new \WP_Error('no_content', __('No content provided to process.', 'fluent-boards'));
        }

        $settings = $this->getSavedSettings();

        $config = $this->validateGenerationConfig($settings);
        if (is_wp_error($config)) {
            return $config;
        }

        $userPrompt   = $this->buildUserPrompt($action, $content, $prompt, $context);
        $systemPrompt = $this->getSystemPrompt($tone, $settings);

        $result = $this->callProviderApi($config['provider'], $config['model'], $config['api_key'], $userPrompt, $systemPrompt, 30);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim((string) $result);
    }

    /* -------------------------------------------------------------------------
     * Task intelligence (task-level actions)
     * ---------------------------------------------------------------------- */

    /**
     * Summarize a task (title + description + comment thread) into markdown.
     *
     * @param array $context task_title, board_title, description, comments
     * @return string|\WP_Error
     */
    public function taskSummary($context)
    {
        $system = 'You are a project-management assistant. Summarize the task below for a teammate who needs to get up to speed quickly. '
            . 'Use only the supplied information — do not invent details. '
            . 'Return concise GitHub-flavored Markdown: a one-line TL;DR, then short bullets for key points, open questions, and next steps when the content supports them. '
            . 'No preamble, no code fences.';

        $result = $this->runGeneration($system, "Summarize this task.\n\n" . $this->taskContextText($context), 45);
        if (is_wp_error($result)) {
            return $result;
        }

        return trim((string) $result);
    }

    /**
     * Propose a list of subtask titles from the task content.
     *
     * @param array $context task_title, board_title, description
     * @return array|\WP_Error list of sanitized title strings
     */
    public function taskSubtasks($context)
    {
        $system = 'You break a task down into clear, actionable subtasks. '
            . 'Return ONLY a JSON array of short subtask title strings (max 10), ordered logically. '
            . 'Each title is a concise action, no numbering, no extra prose, no code fences.';

        $result = $this->runGeneration($system, "Task to break down:\n\n" . $this->taskContextText($context), 45);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->parseJsonList($result);
    }

    /**
     * Suggest labels (from an allowed list) and a priority for the task.
     *
     * @param array $context           task content
     * @param array $allowedLabels     label names the board offers
     * @param array $allowedPriorities allowed priority keys
     * @return array|\WP_Error ['labels' => [...names], 'priority' => key]
     */
    public function taskSuggestions($context, $allowedLabels, $allowedPriorities)
    {
        $allowedLabels = array_values(array_filter(array_map('strval', (array) $allowedLabels)));
        $allowedPriorities = array_values(array_filter(array_map('strval', (array) $allowedPriorities)));

        $labelList = $allowedLabels ? implode(', ', $allowedLabels) : '(none configured)';
        $priorityList = implode(', ', $allowedPriorities);

        $system = 'You classify project tasks. '
            . 'Return ONLY JSON of the shape {"labels":["..."],"priority":"..."}. '
            . 'Choose labels ONLY from the provided label list (return an empty array if none fit). '
            . 'Choose priority ONLY from the provided priority list. No prose, no code fences.';

        $user = 'Available labels: ' . $labelList . "\n"
            . 'Available priorities: ' . $priorityList . "\n\n"
            . "Task:\n" . $this->taskContextText($context);

        $result = $this->runGeneration($system, $user, 30);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->parseSuggestions($result, $allowedLabels, $allowedPriorities);
    }

    /**
     * Validate config + call the provider with a system/user prompt pair.
     *
     * @return string|\WP_Error
     */
    private function runGeneration($systemPrompt, $userPrompt, $timeout = 30)
    {
        $config = $this->validateGenerationConfig($this->getSavedSettings());
        if (is_wp_error($config)) {
            return $config;
        }

        return $this->callProviderApi($config['provider'], $config['model'], $config['api_key'], $userPrompt, $systemPrompt, $timeout);
    }

    /**
     * Build the task context, keeping description + comments inside one shared
     * character budget so a long task can never produce an unbounded prompt.
     */
    private function taskContextText($context)
    {
        $parts = [];
        if ($title = trim((string) Arr::get($context, 'task_title', ''))) {
            $parts[] = 'Title: ' . $title;
        }
        if ($board = trim((string) Arr::get($context, 'board_title', ''))) {
            $parts[] = 'Board: ' . $board;
        }

        $remaining = max(0, self::MAX_CONTEXT_CHARS - $this->length(implode("\n\n", $parts)));

        // The description gets up to 60% of the budget; comments take what is left.
        if (($description = trim((string) Arr::get($context, 'description', ''))) && $remaining > 0) {
            $description = $this->truncate($description, (int) min($remaining, self::MAX_CONTEXT_CHARS * 0.6));
            $parts[] = "Description:\n" . $description;
            $remaining = max(0, $remaining - $this->length($description));
        }

        if (($comments = trim((string) Arr::get($context, 'comments', ''))) && $remaining > 0) {
            // Trim from the start so the most recent comments survive.
            $parts[] = "Comments:\n" . $this->truncateStart($comments, $remaining);
        }

        return implode("\n\n", $parts);
    }

    private function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen((string) $text) : strlen((string) $text);
    }

    /**
     * Keep the beginning of the text, dropping the overflow.
     */
    private function truncate($text, $limit)
    {
        $text = (string) $text;
        if ($limit <= 0 || $this->length($text) <= $limit) {
            return $text;
        }

        $cut = function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);

        return $cut . "\n… [truncated]";
    }

    /**
     * Keep the end of the text (most recent content), dropping the older overflow.
     */
    private function truncateStart($text, $limit)
    {
        $text = (string) $text;
        if ($limit <= 0 || $this->length($text) <= $limit) {
            return $text;
        }

        $cut = function_exists('mb_substr') ? mb_substr($text, -$limit) : substr($text, -$limit);

        return "… [older content truncated]\n" . $cut;
    }

    private function parseJsonList($result)
    {
        $decoded = json_decode(trim((string) $result), true);
        if (!is_array($decoded) && preg_match('/\[.*\]/s', (string) $result, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $title = $item;
            } elseif (is_array($item)) {
                $title = Arr::get($item, 'title', '');
            } else {
                continue;
            }
            $title = sanitize_text_field(trim((string) $title));
            if ($title !== '') {
                $items[] = $title;
            }
        }

        return array_slice($items, 0, 15);
    }

    private function parseSuggestions($result, $allowedLabels, $allowedPriorities)
    {
        $decoded = json_decode(trim((string) $result), true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', (string) $result, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $labels = [];
        foreach ((array) Arr::get($decoded, 'labels', []) as $label) {
            $label = trim((string) $label);
            foreach ($allowedLabels as $allowed) {
                if (strtolower($allowed) === strtolower($label)) {
                    $labels[] = $allowed;
                    break;
                }
            }
        }

        $priority = sanitize_text_field((string) Arr::get($decoded, 'priority', ''));
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = '';
        }

        return [
            'labels'   => array_values(array_unique($labels)),
            'priority' => $priority,
        ];
    }

    /**
     * @return array{provider:string,model:string,api_key:string}|\WP_Error
     */
    private function validateGenerationConfig($settings)
    {
        if (Arr::get($settings, 'is_enabled') !== 'yes') {
            return new \WP_Error('ai_disabled', __('AI features are not enabled. Please configure AI in Settings.', 'fluent-boards'));
        }

        $provider = Arr::get($settings, 'provider', '');
        $apiKey   = Arr::get($settings, 'api_key', '');
        $model    = Arr::get($settings, 'model', '');

        if (!$provider || !in_array($provider, array_keys($this->providerModels), true)) {
            return new \WP_Error('missing_provider', __('AI provider is not configured. Please set it in Settings.', 'fluent-boards'));
        }

        if ($provider !== 'wordpress' && !$apiKey) {
            return new \WP_Error('missing_api_key', __('AI API key is not configured. Please add it in Settings.', 'fluent-boards'));
        }

        $resolvedModel = $this->resolveModel($provider, $model);
        if (!$resolvedModel) {
            return new \WP_Error('missing_model', __('AI model is not configured. Please set it in Settings.', 'fluent-boards'));
        }

        return [
            'provider' => $provider,
            'model'    => $resolvedModel,
            'api_key'  => $apiKey,
        ];
    }

    private function getSystemPrompt($tone = '', $settings = [])
    {
        $prompt = 'You are a writing assistant embedded in a project-management task editor. '
            . 'You help write and improve task descriptions. Write clearly and concisely for a work context. '
            . 'Return ONLY the resulting text in GitHub-flavored Markdown. '
            . 'Do not add explanations, preamble, or wrap the answer in code fences. '
            . 'Use headings, bullet lists, and checklists (- [ ]) where they make the description clearer. '
            . 'Keep any existing Markdown structure intact unless asked to change it.';

        if ($tone) {
            $prompt .= ' Use a ' . strtolower(sanitize_text_field($tone)) . ' tone.';
        }

        $custom = trim((string) Arr::get($settings, 'custom_prompt', ''));
        if ($custom) {
            $prompt .= "\n\nAdditional instructions: " . $custom;
        }

        return $prompt;
    }

    private function buildUserPrompt($action, $content, $prompt, $context = [])
    {
        $contextText = $this->buildContextText($context);

        switch ($action) {
            case 'write':
                $base = "Write a task description based on this instruction:\n\n" . ($prompt ?: $content);
                if ($content && $prompt) {
                    $base .= "\n\nExisting text for reference:\n" . $content;
                }
                return $contextText . $base;
            case 'improve':
                return $contextText . "Improve the following task description. Make it clearer and better structured while keeping the meaning:\n\n" . $content;
            case 'summarize':
                return $contextText . "Summarize the following task description into a short, clear TL;DR:\n\n" . $content;
            case 'shorten':
                return $contextText . "Make the following task description shorter and more concise:\n\n" . $content;
            case 'expand':
                return $contextText . "Expand the following task description with more helpful detail:\n\n" . $content;
            case 'format':
                return $contextText . "Reformat the following task description into clean Markdown with headings, bullet points, and a checklist where appropriate. Do not change the meaning:\n\n" . $content;
            case 'fix_grammar':
                return $contextText . "Fix grammar, spelling, and punctuation in the following text. Keep the wording and Markdown otherwise unchanged:\n\n" . $content;
            case 'custom':
                return $contextText . $prompt . ($content ? "\n\nText:\n" . $content : '');
            default:
                return $content;
        }
    }

    private function buildContextText($context)
    {
        if (!is_array($context) || empty($context)) {
            return '';
        }

        $lines = [];
        if ($title = sanitize_text_field(Arr::get($context, 'task_title', ''))) {
            $lines[] = 'Task title: ' . $title;
        }
        if ($board = sanitize_text_field(Arr::get($context, 'board_title', ''))) {
            $lines[] = 'Board: ' . $board;
        }

        if (!$lines) {
            return '';
        }

        return "Context for the task you are helping with:\n" . implode("\n", $lines) . "\n\n";
    }

    /* -------------------------------------------------------------------------
     * Provider dispatch
     * ---------------------------------------------------------------------- */

    private function callProviderApi($provider, $model, $apiKey, $userPrompt, $systemPrompt = '', $timeout = 30)
    {
        switch ($provider) {
            case 'open_ai':
                return $this->callOpenAi($model, $apiKey, $userPrompt, $systemPrompt, $timeout);
            case 'claude':
                return $this->callClaude($model, $apiKey, $userPrompt, $systemPrompt, $timeout);
            case 'gemini':
                return $this->callGemini($model, $apiKey, $userPrompt, $systemPrompt, $timeout);
            case 'wordpress':
                return $this->callWordPress($model, $userPrompt, $systemPrompt, $timeout);
            default:
                return new \WP_Error('invalid_provider', __('Invalid AI provider.', 'fluent-boards'));
        }
    }

    private function callWordPress($model, $userPrompt, $systemPrompt, $timeout)
    {
        $filtered = apply_filters('fluent_boards/wordpress_ai_generate', null, $userPrompt, $systemPrompt, $model, $timeout);
        if ($filtered !== null) {
            return $filtered;
        }

        if (function_exists('wp_ai_client_prompt')) {
            $prompt = wp_ai_client_prompt($userPrompt);
            if ($systemPrompt) {
                $prompt->using_system_instruction($systemPrompt);
            }
            if ($prompt->is_supported_for_text_generation()) {
                $result = $prompt->generate_text();
                if (is_wp_error($result)) {
                    return $result;
                }
                if (empty($result)) {
                    return new \WP_Error('empty_response', __('No content generated by WordPress AI client. Please try again.', 'fluent-boards'));
                }
                return $result;
            }
            return new \WP_Error('not_supported', __('WordPress AI client is not configured or supported on this site.', 'fluent-boards'));
        }

        return new \WP_Error(
            'wordpress_ai_not_supported',
            __('WordPress AI Client functions are not available on this WordPress installation. Please ensure you have an AI provider plugin or WordPress AI Core features enabled.', 'fluent-boards')
        );
    }

    private function callOpenAi($model, $apiKey, $userPrompt, $systemPrompt, $timeout)
    {
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'                 => $model,
                'messages'              => $messages,
                'max_completion_tokens' => 2048,
            ]),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', __('Failed to connect to OpenAI: ', 'fluent-boards') . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new \WP_Error('api_error', Arr::get($body, 'error.message', __('Unknown error from OpenAI.', 'fluent-boards')));
        }

        $content = Arr::get($body, 'choices.0.message.content', '');
        if (empty($content)) {
            return new \WP_Error('empty_response', __('No content generated. Please try again.', 'fluent-boards'));
        }

        return $content;
    }

    private function callClaude($model, $apiKey, $userPrompt, $systemPrompt, $timeout)
    {
        $data = [
            'model'      => $model,
            'max_tokens' => 2048,
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if ($systemPrompt) {
            $data['system'] = $systemPrompt;
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => $timeout,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode($data),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', __('Failed to connect to Claude: ', 'fluent-boards') . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new \WP_Error('api_error', Arr::get($body, 'error.message', __('Unknown error from Claude.', 'fluent-boards')));
        }

        $content = Arr::get($body, 'content.0.text', '');
        if (empty($content)) {
            return new \WP_Error('empty_response', __('No content generated. Please try again.', 'fluent-boards'));
        }

        return $content;
    }

    private function callGemini($model, $apiKey, $userPrompt, $systemPrompt, $timeout)
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

        $data = [
            'contents'         => [
                ['parts' => [['text' => $userPrompt]]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 2048,
            ],
        ];

        if ($systemPrompt) {
            $data['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $apiKey,
            ],
            'body' => wp_json_encode($data),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', __('Failed to connect to Gemini: ', 'fluent-boards') . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new \WP_Error('api_error', Arr::get($body, 'error.message', __('Unknown error from Gemini.', 'fluent-boards')));
        }

        $content = Arr::get($body, 'candidates.0.content.parts.0.text', '');
        if (empty($content)) {
            return new \WP_Error('empty_response', __('No content generated. Please try again.', 'fluent-boards'));
        }

        return $content;
    }

    /* -------------------------------------------------------------------------
     * Storage helpers
     * ---------------------------------------------------------------------- */

    private function getSavedSettings()
    {
        $defaults = [
            'is_enabled'    => 'no',
            'provider'      => '',
            'api_key'       => '',
            'model'         => 'auto',
            'custom_prompt' => '',
        ];

        $settings = array_merge($this->getSavedPreferences(), $this->getSavedCredentials());

        return wp_parse_args($settings, $defaults);
    }

    private function getSavedCredentials()
    {
        $credentials = get_option($this->credentialsOptionKey, []);
        if (!is_array($credentials)) {
            $credentials = [];
        }

        $model = sanitize_text_field(Arr::get($credentials, 'model', 'auto'));

        return [
            'provider'   => $this->normalizeProvider(Arr::get($credentials, 'provider', '')),
            'model'      => $model ?: 'auto',
            'api_key'    => sanitize_text_field(Arr::get($credentials, 'api_key', '')),
            'created_by' => sanitize_text_field(Arr::get($credentials, 'created_by', '')),
        ];
    }

    private function getSavedPreferences()
    {
        $preferences = fluent_boards_get_option($this->settingsOptionKey, []);
        if (!is_array($preferences) || empty($preferences)) {
            // Fall back to the pre-release key so an existing toggle is not lost.
            $legacy = fluent_boards_get_option($this->legacySettingsOptionKey, []);
            $preferences = is_array($legacy) ? $legacy : [];
        }

        return [
            'is_enabled'    => sanitize_text_field(Arr::get($preferences, 'is_enabled', 'no')) === 'yes' ? 'yes' : 'no',
            'custom_prompt' => sanitize_textarea_field(Arr::get($preferences, 'custom_prompt', '')),
        ];
    }

    private function normalizeProvider($provider)
    {
        $provider = sanitize_key($provider);
        return $provider === 'openai' ? 'open_ai' : $provider;
    }

    private function resolveModel($provider, $model)
    {
        $model = $model ?: 'auto';

        if ($model !== 'auto') {
            return $model;
        }

        return Arr::get($this->autoProviderModels, $provider, '');
    }

    /**
     * Whether AI writing is enabled and configured (used to gate the editor UI).
     */
    public function isReady()
    {
        $settings = $this->getSavedSettings();

        if (Arr::get($settings, 'is_enabled') !== 'yes') {
            return false;
        }

        $provider = Arr::get($settings, 'provider', '');
        if (!$provider) {
            return false;
        }

        if ($provider !== 'wordpress' && !Arr::get($settings, 'api_key', '')) {
            return false;
        }

        return true;
    }
}
