<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions.', 'gutenbot'));
}

if (isset($_POST['gutenbot_save_settings'])) {
    check_admin_referer('gutenbot_settings', 'gutenbot_nonce');

    $provider = sanitize_key($_POST['gutenbot_ai_provider'] ?? 'anthropic');
    update_option('gutenbot_ai_provider', in_array($provider, ['anthropic', 'ollama'], true) ? $provider : 'anthropic');

    update_option('gutenbot_ai_api_key',      sanitize_text_field($_POST['gutenbot_ai_api_key'] ?? ''));
    update_option('gutenbot_ai_api_endpoint', esc_url_raw($_POST['gutenbot_ai_api_endpoint'] ?? ''));
    update_option('gutenbot_ai_model',        sanitize_text_field($_POST['gutenbot_ai_model'] ?? ''));

    update_option('gutenbot_ollama_endpoint',           esc_url_raw($_POST['gutenbot_ollama_endpoint'] ?? ''));
    update_option('gutenbot_ollama_model',              sanitize_text_field($_POST['gutenbot_ollama_model'] ?? ''));
    update_option('gutenbot_ollama_embeddings_model',   sanitize_text_field($_POST['gutenbot_ollama_embeddings_model'] ?? ''));

    update_option('gutenbot_ai_indexing_enabled', isset($_POST['gutenbot_ai_indexing_enabled']) ? '1' : '0');
    update_option('gutenbot_embeddings_endpoint', esc_url_raw($_POST['gutenbot_embeddings_endpoint'] ?? ''));
    update_option('gutenbot_embeddings_model',    sanitize_text_field($_POST['gutenbot_embeddings_model'] ?? ''));
    update_option('gutenbot_embeddings_api_key',  sanitize_text_field($_POST['gutenbot_embeddings_api_key'] ?? ''));

    update_option('gutenbot_file_size_limit', max(1, (int) ($_POST['gutenbot_file_size_limit'] ?? 10485760)));

    $saved = true;
}

$provider        = get_option('gutenbot_ai_provider', 'anthropic');
$api_key         = get_option('gutenbot_ai_api_key', '');
$endpoint        = get_option('gutenbot_ai_api_endpoint', 'https://api.anthropic.com/v1/messages');
$model           = get_option('gutenbot_ai_model', 'claude-sonnet-4-6');
$ollama_endpoint          = get_option('gutenbot_ollama_endpoint', 'http://ollama:11434/api/chat');
$ollama_model             = get_option('gutenbot_ollama_model', 'llama3.2');
$ollama_embeddings_model  = get_option('gutenbot_ollama_embeddings_model', 'nomic-embed-text');
$size_limit      = (int) get_option('gutenbot_file_size_limit', 10485760);

$ai_indexing_enabled  = get_option('gutenbot_ai_indexing_enabled', '0') === '1';
$embeddings_endpoint  = get_option('gutenbot_embeddings_endpoint', 'https://api.openai.com/v1/embeddings');
$embeddings_model     = get_option('gutenbot_embeddings_model', 'text-embedding-3-small');
$embeddings_api_key   = get_option('gutenbot_embeddings_api_key', '');

$env         = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
$is_local    = in_array($env, ['local', 'development'], true);

global $wpdb;
$rules  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gutenbot_rules ORDER BY created_at DESC");
$notice = sanitize_key($_GET['gutenbot_notice'] ?? '');
?>
<div class="wrap">
    <h1><?php esc_html_e('GutenBot — Settings', 'gutenbot'); ?></h1>

    <?php if (!empty($saved)): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'gutenbot'); ?></p></div>
    <?php endif; ?>
    <?php if ($notice === 'rule_saved'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule saved.', 'gutenbot'); ?></p></div>
    <?php elseif ($notice === 'rule_deleted'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule deleted.', 'gutenbot'); ?></p></div>
    <?php endif; ?>

    <?php if ($is_local && $provider !== 'ollama'): ?>
        <div class="notice notice-info">
            <p>
                <?php esc_html_e('Your site is running in a local environment. You can switch the AI provider to Ollama below for free, private page generation — no API key required.', 'gutenbot'); ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('gutenbot_settings', 'gutenbot_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('AI Provider', 'gutenbot'); ?></th>
                <td>
                    <fieldset>
                        <label style="display:inline-block;margin-right:1.5em;">
                            <input type="radio" name="gutenbot_ai_provider" value="anthropic"
                                <?php checked($provider, 'anthropic'); ?>>
                            <?php esc_html_e('Anthropic (Claude)', 'gutenbot'); ?>
                        </label>
                        <label style="display:inline-block;">
                            <input type="radio" name="gutenbot_ai_provider" value="ollama"
                                <?php checked($provider, 'ollama'); ?>>
                            <?php esc_html_e('Ollama (local)', 'gutenbot'); ?>
                        </label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Ollama runs models locally — no API key or internet connection required.', 'gutenbot'); ?></p>
                </td>
            </tr>
        </table>

        <tbody id="gutenbot-anthropic-settings"<?php echo $provider === 'ollama' ? ' style="display:none"' : ''; ?>>
        <h3 style="padding-left:2px;"><?php esc_html_e('Anthropic Settings', 'gutenbot'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="gutenbot_ai_api_key"><?php esc_html_e('API Key', 'gutenbot'); ?></label></th>
                <td>
                    <input type="password" id="gutenbot_ai_api_key" name="gutenbot_ai_api_key"
                        value="<?php echo esc_attr($api_key); ?>" class="regular-text" autocomplete="off">
                    <p class="description">
                        <?php esc_html_e('Your Anthropic API key. Leave blank to use the ANTHROPIC_API_KEY environment variable.', 'gutenbot'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ai_api_endpoint"><?php esc_html_e('Endpoint', 'gutenbot'); ?></label></th>
                <td>
                    <input type="url" id="gutenbot_ai_api_endpoint" name="gutenbot_ai_api_endpoint"
                        value="<?php echo esc_attr($endpoint); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ai_model"><?php esc_html_e('Model', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="gutenbot_ai_model" name="gutenbot_ai_model"
                        value="<?php echo esc_attr($model); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('E.g., claude-sonnet-4-6', 'gutenbot'); ?></p>
                </td>
            </tr>
        </table>
        <p style="padding-left:2px;margin-top:4px;">
            <button type="button" id="gutenbot-test-anthropic" class="button">
                <?php esc_html_e('Test Connection', 'gutenbot'); ?>
            </button>
            <span id="gutenbot-test-anthropic-result" style="margin-left:10px;vertical-align:middle;"></span>
        </p>
        </tbody>

        <div id="gutenbot-ollama-settings"<?php echo $provider !== 'ollama' ? ' style="display:none"' : ''; ?>>
        <h3 style="padding-left:2px;"><?php esc_html_e('Ollama Settings', 'gutenbot'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="gutenbot_ollama_endpoint"><?php esc_html_e('Endpoint', 'gutenbot'); ?></label></th>
                <td>
                    <input type="url" id="gutenbot_ollama_endpoint" name="gutenbot_ollama_endpoint"
                        value="<?php echo esc_attr($ollama_endpoint); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Default: http://ollama:11434/api/chat — run', 'gutenbot'); ?>
                        <code>ollama serve</code>
                        <?php esc_html_e('to start the local server.', 'gutenbot'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ollama_model"><?php esc_html_e('Model', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="gutenbot_ollama_model" name="gutenbot_ollama_model"
                        value="<?php echo esc_attr($ollama_model); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Any model pulled with', 'gutenbot'); ?>
                        <code>ollama pull &lt;model&gt;</code>.
                        <?php esc_html_e('E.g., llama3.2, mistral, phi4.', 'gutenbot'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_ollama_embeddings_model"><?php esc_html_e('Embeddings Model', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="gutenbot_ollama_embeddings_model" name="gutenbot_ollama_embeddings_model"
                        value="<?php echo esc_attr($ollama_embeddings_model); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Used for AI indexing. Default:', 'gutenbot'); ?>
                        <code>nomic-embed-text</code>.
                        <?php esc_html_e('Pull with', 'gutenbot'); ?>
                        <code>ollama pull nomic-embed-text</code>.
                    </p>
                </td>
            </tr>
        </table>
        <p style="padding-left:2px;margin-top:4px;">
            <button type="button" id="gutenbot-test-ollama" class="button">
                <?php esc_html_e('Test Connection', 'gutenbot'); ?>
            </button>
            <span id="gutenbot-test-ollama-result" style="margin-left:10px;vertical-align:middle;"></span>
        </p>
        </div>

        <h3 style="padding-left:2px;"><?php esc_html_e('AI Indexing', 'gutenbot'); ?></h3>
        <p class="description" style="padding-left:2px;">
            <?php esc_html_e('When enabled, each page re-index makes one AI call to classify and summarise the page, and one embedding call per section. Requires a separate embeddings API key.', 'gutenbot'); ?>
        </p>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Enable AI Indexing', 'gutenbot'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="gutenbot_ai_indexing_enabled" value="1"
                            <?php checked($ai_indexing_enabled); ?>>
                        <?php esc_html_e('Use AI for page classification, summaries, and section embeddings during indexing', 'gutenbot'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <div id="gutenbot-embeddings-settings"<?php echo $ai_indexing_enabled ? '' : ' style="display:none"'; ?>>
        <h3 style="padding-left:2px;"><?php esc_html_e('Embeddings Settings', 'gutenbot'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="gutenbot_embeddings_endpoint"><?php esc_html_e('Embeddings Endpoint', 'gutenbot'); ?></label></th>
                <td>
                    <input type="url" id="gutenbot_embeddings_endpoint" name="gutenbot_embeddings_endpoint"
                        value="<?php echo esc_attr($embeddings_endpoint); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Any OpenAI-compatible endpoint. E.g., https://api.openai.com/v1/embeddings or a local Ollama endpoint.', 'gutenbot'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_embeddings_model"><?php esc_html_e('Embeddings Model', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="gutenbot_embeddings_model" name="gutenbot_embeddings_model"
                        value="<?php echo esc_attr($embeddings_model); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('E.g., text-embedding-3-small, nomic-embed-text', 'gutenbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gutenbot_embeddings_api_key"><?php esc_html_e('Embeddings API Key', 'gutenbot'); ?></label></th>
                <td>
                    <input type="password" id="gutenbot_embeddings_api_key" name="gutenbot_embeddings_api_key"
                        value="<?php echo esc_attr($embeddings_api_key); ?>" class="regular-text" autocomplete="off">
                    <p class="description">
                        <?php esc_html_e('Leave blank to use the EMBEDDINGS_API_KEY environment variable.', 'gutenbot'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <p style="padding-left:2px;margin-top:4px;">
            <button type="button" id="gutenbot-test-embeddings" class="button">
                <?php esc_html_e('Test Connection', 'gutenbot'); ?>
            </button>
            <span id="gutenbot-test-embeddings-result" style="margin-left:10px;vertical-align:middle;"></span>
        </p>
        </div>

        <h3 style="padding-left:2px;"><?php esc_html_e('Upload Settings', 'gutenbot'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="gutenbot_file_size_limit"><?php esc_html_e('Max File Size (bytes)', 'gutenbot'); ?></label></th>
                <td>
                    <input type="number" id="gutenbot_file_size_limit" name="gutenbot_file_size_limit"
                        value="<?php echo esc_attr($size_limit); ?>" class="small-text" min="1">
                    <p class="description"><?php esc_html_e('Default: 10485760 (10 MB)', 'gutenbot'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'gutenbot'), 'primary', 'gutenbot_save_settings'); ?>
    </form>

    <script>
    (function () {
        var radios    = document.querySelectorAll('input[name="gutenbot_ai_provider"]');
        var anthropic = document.getElementById('gutenbot-anthropic-settings');
        var ollama    = document.getElementById('gutenbot-ollama-settings');

        function toggle(val) {
            anthropic.style.display = val === 'ollama' ? 'none' : '';
            ollama.style.display    = val === 'ollama' ? '' : 'none';
        }

        radios.forEach(function (r) {
            r.addEventListener('change', function () { toggle(this.value); });
        });

        var aiIndexingCb     = document.querySelector('input[name="gutenbot_ai_indexing_enabled"]');
        var embeddingsPanel  = document.getElementById('gutenbot-embeddings-settings');
        if (aiIndexingCb && embeddingsPanel) {
            aiIndexingCb.addEventListener('change', function () {
                embeddingsPanel.style.display = this.checked ? '' : 'none';
            });
        }

        var apiConfig = <?php echo wp_json_encode(['root' => esc_url_raw(rest_url()), 'nonce' => wp_create_nonce('wp_rest')]); ?>;

        function testConnection(provider, btn, resultEl) {
            btn.disabled        = true;
            resultEl.textContent = '…';
            resultEl.style.color = '#666';

            fetch(apiConfig.root + 'gutenbot/v1/test-connection', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   apiConfig.nonce,
                },
                body: JSON.stringify({ provider: provider }),
            })
            .then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, data: data }; });
            })
            .then(function (res) {
                var msg = res.data.message || (res.ok ? 'OK' : 'Unknown error');
                if (res.ok && res.data.ok) {
                    resultEl.textContent = '✓ ' + msg;
                    resultEl.style.color = '#00a32a';
                } else if (res.ok && res.data.ok === false) {
                    resultEl.textContent = '⚠ ' + msg;
                    resultEl.style.color = '#dba617';
                } else {
                    resultEl.textContent = '✗ ' + msg;
                    resultEl.style.color = '#d63638';
                }
            })
            .catch(function (err) {
                resultEl.textContent = '✗ Request failed: ' + err.message;
                resultEl.style.color = '#d63638';
            })
            .finally(function () { btn.disabled = false; });
        }

        document.getElementById('gutenbot-test-anthropic').addEventListener('click', function () {
            testConnection('anthropic', this, document.getElementById('gutenbot-test-anthropic-result'));
        });
        document.getElementById('gutenbot-test-ollama').addEventListener('click', function () {
            testConnection('ollama', this, document.getElementById('gutenbot-test-ollama-result'));
        });
        document.getElementById('gutenbot-test-embeddings').addEventListener('click', function () {
            testConnection('embeddings', this, document.getElementById('gutenbot-test-embeddings-result'));
        });
    }());
    </script>

    <hr>

    <h2><?php esc_html_e('Custom Rules & Instructions', 'gutenbot'); ?></h2>
    <p class="description">
        <?php esc_html_e('Rules are appended to every AI prompt as explicit instructions. Use them to enforce tone, structure, naming conventions, or any site-specific requirements.', 'gutenbot'); ?>
    </p>

    <?php if ($rules): ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:800px;margin-top:1em;">
            <thead>
                <tr>
                    <th style="width:200px;"><?php esc_html_e('Label', 'gutenbot'); ?></th>
                    <th><?php esc_html_e('Instruction', 'gutenbot'); ?></th>
                    <th style="width:80px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><code><?php echo esc_html($rule->rule_key); ?></code></td>
                        <td><?php echo esc_html($rule->rule_value); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  onsubmit="return confirm('<?php esc_attr_e('Delete this rule?', 'gutenbot'); ?>')">
                                <?php wp_nonce_field('gutenbot_delete_rule', 'gutenbot_nonce'); ?>
                                <input type="hidden" name="action" value="gutenbot_delete_rule">
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->id); ?>">
                                <?php submit_button(__('Delete', 'gutenbot'), 'delete small', '', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color:#666;"><?php esc_html_e('No rules yet. Add one below.', 'gutenbot'); ?></p>
    <?php endif; ?>

    <h3 style="margin-top:1.5em;"><?php esc_html_e('Add Rule', 'gutenbot'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:800px;">
        <?php wp_nonce_field('gutenbot_add_rule', 'gutenbot_nonce'); ?>
        <input type="hidden" name="action" value="gutenbot_add_rule">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="rule_key"><?php esc_html_e('Label', 'gutenbot'); ?></label></th>
                <td>
                    <input type="text" id="rule_key" name="rule_key" class="regular-text" required
                        placeholder="<?php esc_attr_e('e.g. tone, cta_text, page_structure', 'gutenbot'); ?>">
                    <p class="description"><?php esc_html_e('A short identifier. Saving with an existing label overwrites it.', 'gutenbot'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rule_value"><?php esc_html_e('Instruction', 'gutenbot'); ?></label></th>
                <td>
                    <textarea id="rule_value" name="rule_value" rows="4" class="large-text" required
                        placeholder="<?php esc_attr_e('e.g. Always write in a friendly, conversational tone. Avoid jargon.', 'gutenbot'); ?>"></textarea>
                    <p class="description"><?php esc_html_e('Plain-language instruction appended to every AI prompt.', 'gutenbot'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Add Rule', 'gutenbot'), 'secondary', '', false); ?>
    </form>
</div>
