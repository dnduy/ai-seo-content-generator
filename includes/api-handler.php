<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

function aiseo_call_gemini_api($prompt, $model = 'gemini-1.5-flash') {
    $api_key = aiseo_get_api_key('aiseo_gemini_api_key');
    if (empty($api_key)) {
        error_log('AISEO: Gemini API key is not configured.');
        return new WP_Error('no_api_key', 'Gemini API key is not configured.');
    }

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . ($model === 'gemini-2.0' ? 'gemini-2.0-flash' : 'gemini-1.5-flash') . ':generateContent';

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
            'maxOutputTokens' => 2048
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
    error_log('AISEO: Gemini API response body: ' . $response_body);

    $data = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('AISEO: Invalid JSON response from Gemini API: ' . json_last_error_msg());
        return new WP_Error('json_error', 'Invalid JSON response from Gemini API');
    }

    if (isset($data['error'])) {
        $error_message = $data['error']['message'] ?? 'Unknown Gemini API error';
        error_log('AISEO: Gemini API error: ' . $error_message);
        if ($data['error']['code'] == 429) {
            return new WP_Error('quota_exceeded', $error_message . '. Check your Gemini plan at https://ai.google.dev/gemini-api/docs/rate-limits', array('status' => 429));
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

    $body = array(
        'model' => 'deepseek/deepseek-chat:free',
        'messages' => array(
            array('role' => 'user', 'content' => $prompt)
        ),
        'temperature' => 0.7,
        'max_tokens' => 2048
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
        error_log('AISEO: DeepSeek API response body: ' . $response_body);

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('AISEO: Invalid JSON response from DeepSeek API: ' . json_last_error_msg());
            return new WP_Error('json_error', 'Invalid JSON response from DeepSeek API');
        }

        if (isset($data['error'])) {
            $error_message = $data['error']['message'] ?? 'Unknown DeepSeek API error';
            error_log('AISEO: DeepSeek API error: ' . $error_message);
            if ($data['error']['code'] == 'rate_limit_exceeded') {
                return new WP_Error('quota_exceeded', $error_message . '. Check your DeepSeek plan at https://openrouter.ai/', array('status' => 429));
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

// Multi-API fallback function
function aiseo_generate_content_with_fallback($prompt, $preferred_api = 'gemini-1.5') {
    $apis = array();
    
    // Set API priority based on preference
    if ($preferred_api === 'deepseek') {
        $apis = array('deepseek', 'gemini-1.5', 'gemini-2.0');
    } elseif ($preferred_api === 'gemini-2.0') {
        $apis = array('gemini-2.0', 'gemini-1.5', 'deepseek');
    } else {
        $apis = array('gemini-1.5', 'gemini-2.0', 'deepseek');
    }
    
    $last_error = null;
    
    foreach ($apis as $api) {
        error_log('AISEO: Trying API: ' . $api);
        
        $result = ($api === 'deepseek') ? 
            aiseo_call_deepseek_api($prompt) : 
            aiseo_call_gemini_api($prompt, $api);
        
        if (!is_wp_error($result)) {
            error_log('AISEO: Success with API: ' . $api);
            return $result;
        }
        
        $last_error = $result;
        error_log('AISEO: Failed with API ' . $api . ': ' . $result->get_error_message());
        
        // Don't try other APIs if it's a quota/rate limit issue with the preferred one
        if (in_array($result->get_error_code(), array('quota_exceeded', 'rate_limit_exceeded'))) {
            if ($api === $preferred_api) {
                error_log('AISEO: Quota exceeded for preferred API, trying alternatives');
                continue;
            }
        }
    }
    
    error_log('AISEO: All APIs failed');
    return $last_error ?: new WP_Error('all_apis_failed', 'All APIs failed to generate content');
}
?>