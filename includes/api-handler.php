<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function aiseo_call_gemini_api($prompt, $model = 'gemini-3-flash') {
    $api_key = aiseo_get_api_key('aiseo_gemini_api_key');
    if (empty($api_key)) {
        error_log('AISEO: Gemini API key is not configured.');
        return new WP_Error('no_api_key', 'Gemini API key is not configured.');
    }

    // Map model names to Google Gemini API model IDs (Dec 2025)
    $model_map = array(
        'gemini-studio' => 'gemini-3-flash', // Licensed Studio key can target latest flash
        'gemini-3-flash' => 'gemini-3-flash',
        'gemini-2.0' => 'gemini-2.0-flash',
        'gemini-1.5' => 'gemini-1.5-flash'
    );
    $gemini_model = isset($model_map[$model]) ? $model_map[$model] : 'gemini-3-flash';
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $gemini_model . ':generateContent';

    $body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => $prompt)
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0.7,
            'maxOutputTokens' => 4096
        )
    );

    error_log('AISEO: Sending Gemini API request with prompt: ' . $prompt . ' and model: ' . $model);

    $response = wp_remote_post($api_url . '?key=' . $api_key, array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($body),
        'timeout' => 60,
    ));

    if (is_wp_error($response)) {
        error_log('AISEO: Gemini API request failed: ' . $response->get_error_message());
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    error_log('AISEO: Gemini API response code: ' . $response_code);
    error_log('AISEO: Gemini API response body: ' . substr($response_body, 0, 500));

    // Check for quota/rate limit errors by HTTP status code first
    if ($response_code === 429 || $response_code === 503) {
        $error_message = 'Rate limit exceeded. Gemini API quota exhausted.';
        error_log('AISEO: Gemini API quota exceeded (HTTP ' . $response_code . ')');
        return new WP_Error('quota_exceeded', $error_message . ' Please wait a few minutes before trying again or check your API plan at https://ai.google.dev/gemini-api/docs/rate-limits', array('status' => 429));
    }

    $data = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('AISEO: Invalid JSON response from Gemini API: ' . json_last_error_msg());
        return new WP_Error('json_error', 'Invalid JSON response from Gemini API');
    }

    if (isset($data['error'])) {
        $error_message = $data['error']['message'] ?? 'Unknown Gemini API error';
        $error_code = $data['error']['code'] ?? null;
        error_log('AISEO: Gemini API error (' . $error_code . '): ' . $error_message);
        
        // Check for quota/rate limit in error response
        if ($error_code == 429 || strpos(strtolower($error_message), 'quota') !== false || strpos(strtolower($error_message), 'rate limit') !== false) {
            return new WP_Error('quota_exceeded', $error_message . ' Please wait a few minutes before trying again.', array('status' => 429));
        }
        return new WP_Error('api_error', $error_message);
    }

    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($content)) {
        error_log('AISEO: No content returned from Gemini API. Full response: ' . print_r($data, true));
        return new WP_Error('api_error', 'No content returned from Gemini API');
    }

    return $content;
}

function aiseo_call_deepseek_api($prompt) {
    $api_key = aiseo_get_api_key('aiseo_deepseek_api_key');
    if (empty($api_key)) {
        error_log('AISEO: DeepSeek API key is not configured.');
        return new WP_Error('no_api_key', 'DeepSeek API key is not configured.');
    }

    $api_url = 'https://openrouter.ai/api/v1/chat/completions';

    // Use DeepSeek R1 model via OpenRouter (latest version as of Dec 2025)
    // Note: DeepSeek R1 includes advanced reasoning capabilities
    $body = array(
        'model' => 'deepseek/deepseek-r1',
        'messages' => array(
            array('role' => 'user', 'content' => $prompt)
        ),
        'temperature' => 0.7,
        'max_tokens' => 4096
    );

    error_log('AISEO: Sending DeepSeek API request with prompt: ' . $prompt);

    $max_retries = 2;
    $attempt = 0;
    $retry_delay = 5; // seconds

    while ($attempt < $max_retries) {
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            error_log('AISEO: DeepSeek API request failed: ' . $response->get_error_message());
            if ($response->get_error_code() === 'http_request_failed' && strpos($response->get_error_message(), 'timed out') !== false && $attempt < $max_retries - 1) {
                error_log('AISEO: DeepSeek timeout, retrying in ' . $retry_delay . ' seconds...');
                sleep($retry_delay);
                $attempt++;
                continue;
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('AISEO: DeepSeek API response code: ' . $response_code);
        error_log('AISEO: DeepSeek API response body: ' . substr($response_body, 0, 500));

        // Check for quota/rate limit errors by HTTP status code first
        if ($response_code === 429 || $response_code === 503) {
            $error_message = 'Rate limit exceeded. DeepSeek API quota exhausted.';
            error_log('AISEO: DeepSeek API quota exceeded (HTTP ' . $response_code . ')');
            return new WP_Error('quota_exceeded', $error_message . ' Please wait a few minutes before trying again or check your OpenRouter plan at https://openrouter.ai/', array('status' => 429));
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AISEO: Invalid JSON response from DeepSeek API: ' . json_last_error_msg());
            return new WP_Error('json_error', 'Invalid JSON response from DeepSeek API');
        }

        if (isset($data['error'])) {
            $error_message = $data['error']['message'] ?? 'Unknown DeepSeek API error';
            $error_code = $data['error']['code'] ?? null;
            error_log('AISEO: DeepSeek API error (' . $error_code . '): ' . $error_message);
            
            // Check for quota/rate limit in error response
            if ($error_code == 'rate_limit_exceeded' || strpos(strtolower($error_message), 'quota') !== false || strpos(strtolower($error_message), 'rate limit') !== false) {
                return new WP_Error('quota_exceeded', $error_message . ' Please wait a few minutes before trying again.', array('status' => 429));
            }
            return new WP_Error('api_error', $error_message);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (empty($content)) {
            error_log('AISEO: No content returned from DeepSeek API. Full response: ' . print_r($data, true));
            return new WP_Error('api_error', 'No content returned from DeepSeek API');
        }

        return $content;
    }

    error_log('AISEO: Max retries reached for DeepSeek API call.');
    return new WP_Error('max_retries', 'Failed to get content from DeepSeek API after retries.');
}

function aiseo_call_claude_api($prompt, $model = 'claude-opus-4-6') {
    $api_key = aiseo_get_api_key('aiseo_claude_api_key');
    if (empty($api_key)) {
        error_log('AISEO: Claude API key is not configured.');
        return new WP_Error('no_api_key', 'Claude API key is not configured.');
    }

    // Map model names to Anthropic model IDs
    $model_map = array(
        'claude-opus'   => 'claude-opus-4-6',
        'claude-sonnet' => 'claude-sonnet-4-6',
        'claude-haiku'  => 'claude-haiku-4-5',
        'claude-opus-4-6'   => 'claude-opus-4-6',
        'claude-sonnet-4-6' => 'claude-sonnet-4-6',
        'claude-haiku-4-5'  => 'claude-haiku-4-5',
    );
    $claude_model = isset($model_map[$model]) ? $model_map[$model] : 'claude-opus-4-6';

    $body = array(
        'model'      => $claude_model,
        'max_tokens' => 4096,
        'thinking'   => array('type' => 'adaptive'),
        'messages'   => array(
            array('role' => 'user', 'content' => $prompt)
        ),
    );

    error_log('AISEO: Sending Claude API request with model: ' . $claude_model);

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'method'  => 'POST',
        'headers' => array(
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ),
        'body'    => json_encode($body),
        'timeout' => 90,
    ));

    if (is_wp_error($response)) {
        error_log('AISEO: Claude API request failed: ' . $response->get_error_message());
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    error_log('AISEO: Claude API response code: ' . $response_code);
    error_log('AISEO: Claude API response body: ' . substr($response_body, 0, 500));

    if ($response_code === 429 || $response_code === 529) {
        error_log('AISEO: Claude API rate/overload error (HTTP ' . $response_code . ')');
        return new WP_Error('quota_exceeded', 'Claude API rate limit exceeded. Please wait a few minutes before trying again.', array('status' => 429));
    }

    $data = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('AISEO: Invalid JSON response from Claude API: ' . json_last_error_msg());
        return new WP_Error('json_error', 'Invalid JSON response from Claude API');
    }

    if (isset($data['error'])) {
        $error_message = $data['error']['message'] ?? 'Unknown Claude API error';
        $error_type    = $data['error']['type'] ?? null;
        error_log('AISEO: Claude API error (' . $error_type . '): ' . $error_message);

        if ($error_type === 'rate_limit_error' || $error_type === 'overloaded_error') {
            return new WP_Error('quota_exceeded', $error_message . ' Please wait a few minutes before trying again.', array('status' => 429));
        }
        return new WP_Error('api_error', $error_message);
    }

    // Extract text from content blocks (skip thinking blocks)
    $content = '';
    if (!empty($data['content']) && is_array($data['content'])) {
        foreach ($data['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') {
                $content .= $block['text'];
            }
        }
    }

    if (empty($content)) {
        error_log('AISEO: No text content returned from Claude API. Full response: ' . print_r($data, true));
        return new WP_Error('api_error', 'No content returned from Claude API');
    }

    return $content;
}

// Multi-API fallback function
function aiseo_generate_content_with_fallback($prompt, $preferred_api = 'gemini-3-flash') {
    $apis = array();
    
    // Set API priority based on preference
    if (in_array($preferred_api, array('claude-opus', 'claude-sonnet', 'claude-haiku'), true)) {
        $apis = array($preferred_api, 'gemini-studio', 'gemini-3-flash', 'deepseek');
    } elseif ($preferred_api === 'deepseek') {
        $apis = array('deepseek', 'claude-opus', 'gemini-studio', 'gemini-3-flash', 'gemini-2.0');
    } elseif ($preferred_api === 'gemini-2.0') {
        $apis = array('gemini-2.0', 'gemini-studio', 'gemini-3-flash', 'claude-opus', 'deepseek');
    } elseif ($preferred_api === 'gemini-studio' || $preferred_api === 'gemini-3-flash') {
        $apis = array('gemini-studio', 'gemini-3-flash', 'gemini-2.0', 'claude-opus', 'deepseek');
    } else {
        // Default: Gemini Studio/3 Flash as primary, Claude as secondary fallback
        $apis = array('gemini-studio', 'gemini-3-flash', 'claude-opus', 'gemini-2.0', 'deepseek');
    }
    
    $last_error = null;
    $quota_errors = array(); // Track quota errors separately
    
    foreach ($apis as $api) {
        error_log('AISEO: Trying API: ' . $api);
        
        if ($api === 'deepseek') {
            $result = aiseo_call_deepseek_api($prompt);
        } elseif (in_array($api, array('claude-opus', 'claude-sonnet', 'claude-haiku'), true)) {
            $result = aiseo_call_claude_api($prompt, $api);
        } else {
            $result = aiseo_call_gemini_api($prompt, $api);
        }
        
        if (!is_wp_error($result)) {
            error_log('AISEO: Success with API: ' . $api);
            return $result;
        }
        
        $last_error = $result;
        $error_code = $result->get_error_code();
        
        // If this is a quota/rate limit error, skip this API and try next
        if ($error_code === 'quota_exceeded') {
            error_log('AISEO: API ' . $api . ' has quota exceeded, trying next API');
            $quota_errors[] = array('api' => $api, 'message' => $result->get_error_message());
            continue; // Try next API
        }
        
        // For other errors, also try next API if available
        error_log('AISEO: Failed with API ' . $api . ': ' . $result->get_error_message());
    }
    
    // If all APIs failed due to quota, return specific error message
    if (!empty($quota_errors)) {
        $quota_message = 'All available APIs have reached their rate limits. ';
        $quota_message .= 'Please wait a few minutes before trying again. ';
        $quota_message .= 'Consider upgrading your API plans at https://ai.google.dev/ or https://openrouter.ai/';
        error_log('AISEO: All APIs failed with quota exceeded');
        return new WP_Error('all_quota_exceeded', $quota_message);
    }
    
    error_log('AISEO: All APIs failed');
    return $last_error ?: new WP_Error('all_apis_failed', 'All APIs failed to generate content');
}
?>