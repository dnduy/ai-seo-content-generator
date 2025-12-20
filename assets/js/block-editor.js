(function(wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginPostStatusInfo } = wp.editPost;
    const { Button, Modal, TextControl, TextareaControl, SelectControl, CheckboxControl } = wp.components;
    const { useState } = wp.element;
    const { useDispatch, useSelect } = wp.data;
    const { __ } = wp.i18n;

    // Debug: Log to confirm script is loaded
    console.log('AI SEO Content Generator: block-editor.js loaded');
    console.log('AI SEO Content Generator: aiseoSettings available:', typeof aiseoSettings !== 'undefined' ? aiseoSettings : 'NOT FOUND');

    // Test authentication endpoint for debugging
    if (typeof aiseoSettings !== 'undefined' && aiseoSettings.nonce) {
        console.log('Testing authentication endpoint...');
        fetch(aiseoSettings.rest_url.replace('/generate-content', '/test-auth'), {
            method: 'GET',
            credentials: 'include',
            headers: {
                'X-WP-Nonce': aiseoSettings.nonce
            }
        }).then(res => res.json()).then(data => {
            console.log('Authentication test result:', data);
        }).catch(err => {
            console.error('Authentication test error:', err);
        });
    }

    // Rate limiting
    let lastRequestTime = 0;
    const minRequestInterval = 10000; // 10 seconds

    // Register the plugin
    registerPlugin('aiseo-content-generator', {
        render: () => {
            const { insertBlocks } = useDispatch('core/block-editor');
            const { editPost } = useDispatch('core/editor');
            const currentPostTitle = useSelect(select => select('core/editor').getEditedPostAttribute('title'), []);
            const [isOpen, setOpen] = useState(false);
            const [keywords, setKeywords] = useState('');
            const [prompt, setPrompt] = useState('');
            const [length, setLength] = useState('500');
            const [tone, setTone] = useState('neutral');
            const [language, setLanguage] = useState('vi');
            const [api, setApi] = useState('gemini-1.5');
            const [isLoading, setLoading] = useState(false);
            const [autoFillTitle, setAutoFillTitle] = useState(true);

            // Debug: Log when rendering plugin
            console.log('AI SEO Content Generator: Rendering plugin');

            // Handle AI request
            const handleAIRequest = () => {
                if (isLoading) {
                    return; // Prevent multiple requests
                }
                
                if (Date.now() - lastRequestTime < minRequestInterval) {
                    alert(__('Please wait a few seconds before sending another request.', 'ai-seo-content-generator'));
                    return;
                }

                if (!keywords || !prompt) {
                    alert(__('Please provide a keyword and prompt.', 'ai-seo-content-generator'));
                    return;
                }

                console.log('AI SEO Content Generator: Sending AI request with prompt:', prompt);
                lastRequestTime = Date.now();
                setLoading(true);

                // Verify nonce is available
                if (!aiseoSettings || !aiseoSettings.nonce) {
                    console.error('AI SEO Content Generator: nonce not available in aiseoSettings');
                    alert(__('Error: Security settings not loaded. Please refresh the page and try again.', 'ai-seo-content-generator'));
                    setLoading(false);
                    return;
                }

                console.log('AI SEO Content Generator: Using nonce for request');

                wp.apiFetch({
                    path: 'aiseo/v1/generate-content',
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': aiseoSettings.nonce,
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include', // Include cookies for authentication
                    data: {
                        prompt: prompt,
                        keywords: keywords,
                        length: length,
                        tone: tone,
                        language: language,
                        api: api
                    }
                }).then((response) => {
                    console.log('AI SEO Content Generator: API response received', response);
                    if (response.success && response.data) {
                        // Better content parsing function
                        const parseContentToBlocks = (content) => {
                            const blocks = [];
                            
                            // Clean content first
                            let cleanContent = content
                                .replace(/```html\n([\s\S]*?)\n```/g, '$1')
                                .replace(/```[\s\S]*?```/g, '')
                                .replace(/\u003c/g, '<')
                                .replace(/\u003e/g, '>')
                                .trim();

                            // Remove empty tags
                            cleanContent = cleanContent
                                .replace(/<p>\s*<\/p>/g, '')
                                .replace(/<ul>\s*<\/ul>/g, '')
                                .replace(/<li>\s*<\/li>/g, '');

                            // Parse HTML using DOMParser
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(`<div>${cleanContent}</div>`, 'text/html');
                            
                            const processElement = (element) => {
                                const tagName = element.tagName;
                                const textContent = element.textContent.trim();
                                
                                if (!textContent) return null;
                                
                                switch (tagName) {
                                    case 'H1':
                                    case 'H2':
                                    case 'H3':
                                    case 'H4':
                                    case 'H5':
                                    case 'H6':
                                        return wp.blocks.createBlock('core/heading', {
                                            content: element.innerHTML,
                                            level: parseInt(tagName.replace('H', ''))
                                        });
                                    
                                    case 'P':
                                        return wp.blocks.createBlock('core/paragraph', {
                                            content: element.innerHTML
                                        });
                                    
                                    case 'UL':
                                        const listItems = Array.from(element.querySelectorAll('li'))
                                            .map(li => li.innerHTML.trim())
                                            .filter(item => item);
                                        
                                        if (listItems.length > 0) {
                                            return wp.blocks.createBlock('core/list', {
                                                values: `<li>${listItems.join('</li><li>')}</li>`
                                            });
                                        }
                                        break;
                                    
                                    case 'OL':
                                        const numberedItems = Array.from(element.querySelectorAll('li'))
                                            .map(li => li.innerHTML.trim())
                                            .filter(item => item);
                                        
                                        if (numberedItems.length > 0) {
                                            return wp.blocks.createBlock('core/list', {
                                                ordered: true,
                                                values: `<li>${numberedItems.join('</li><li>')}</li>`
                                            });
                                        }
                                        break;
                                    
                                    default:
                                        // For other elements, create as paragraph
                                        if (textContent) {
                                            return wp.blocks.createBlock('core/paragraph', {
                                                content: element.innerHTML
                                            });
                                        }
                                }
                                
                                return null;
                            };
                            
                            // Process all child elements
                            const container = doc.querySelector('div');
                            if (container) {
                                Array.from(container.children).forEach(element => {
                                    const block = processElement(element);
                                    if (block) {
                                        blocks.push(block);
                                    }
                                });
                            }
                            
                            return blocks;
                        };

                        // Parse content and create blocks
                        const blocks = parseContentToBlocks(response.data);

                        // Insert blocks
                        if (blocks.length > 0) {
                            insertBlocks(blocks);
                            
                            // Auto-fill post title if enabled, empty and meta_title is available
                            if (response.meta_title && autoFillTitle && (!currentPostTitle || currentPostTitle.trim() === '')) {
                                editPost({ title: response.meta_title });
                                console.log('AI SEO Content Generator: Auto-filled post title:', response.meta_title);
                            }
                            
                            setOpen(false);
                            
                            // Show success message
                            const successMessage = __('Content generated successfully!', 'ai-seo-content-generator');
                            let notificationMessage = successMessage;
                            
                            if (response.meta_title && autoFillTitle && (!currentPostTitle || currentPostTitle.trim() === '')) {
                                notificationMessage += ' ' + __('Post title has been automatically set.', 'ai-seo-content-generator');
                            }
                            
                            if (response.meta_title && response.meta_description) {
                                const seoMessage = __('SEO guidance has been added at the end of the article. Please follow it to optimize your post.', 'ai-seo-content-generator');
                                notificationMessage += ' ' + seoMessage;
                            }
                            
                            alert(notificationMessage);
                        } else {
                            alert(__('Error: No valid content blocks generated.', 'ai-seo-content-generator'));
                        }
                    } else {
                        alert(__('Error: ', 'ai-seo-content-generator') + (response.message || __('Unknown error occurred.', 'ai-seo-content-generator')));
                    }
                    
                    setLoading(false);
                }).catch((error) => {
                    console.error('AI SEO Content Generator: API error', error);
                    console.log('Error details:', error);
                    
                    let errorMessage = error.message || __('An unknown error occurred', 'ai-seo-content-generator');
                    
                    // Handle specific error codes from REST API response
                    if (error.code === 'quota_exceeded' || error.code === 'all_quota_exceeded') {
                        errorMessage = __('API quota exceeded. All available APIs have reached their rate limits. Please wait a few minutes before trying again, or upgrade your API plans.', 'ai-seo-content-generator');
                    } else if (error.code === 'not_authenticated' || error.code === 'rest_not_authenticated') {
                        errorMessage = __('You must be logged in to WordPress to use this feature. Please log in and try again.', 'ai-seo-content-generator');
                    } else if (error.code === 'forbidden' || error.code === 'rest_forbidden') {
                        errorMessage = __('You do not have permission to generate content. Please contact your site administrator.', 'ai-seo-content-generator');
                    } else if (error.code === 'invalid_nonce' || error.message?.includes('Nonce') || error.message?.includes('verification')) {
                        errorMessage = __('Session expired. Please refresh the page and try again.', 'ai-seo-content-generator');
                    } else if (error.code === 'no_api_key') {
                        errorMessage = __('API keys are not configured. Please contact your site administrator to set up the AI SEO Content Generator.', 'ai-seo-content-generator');
                    } else if (error.message?.includes('cookie') || error.message?.includes('authentication')) {
                        errorMessage = __('Authentication failed. Please ensure you are logged in to WordPress.', 'ai-seo-content-generator');
                    }
                    
                    alert(__('Error: ', 'ai-seo-content-generator') + errorMessage);
                    setLoading(false);
                });
            };

            return (
                wp.element.createElement(
                    wp.element.Fragment,
                    null,
                    wp.element.createElement(
                        PluginPostStatusInfo,
                        null,
                        wp.element.createElement(
                            Button,
                            {
                                variant: 'primary',
                                onClick: () => {
                                    console.log('AI SEO Content Generator: Generate SEO Content button clicked');
                                    setOpen(true);
                                },
                                style: { margin: '10px 0', width: '100%' }
                            },
                            __('Generate SEO Content', 'ai-seo-content-generator')
                        )
                    ),
                    isOpen && wp.element.createElement(
                        Modal,
                        {
                            title: __('AI SEO Content Generator', 'ai-seo-content-generator'),
                            onRequestClose: () => setOpen(false),
                            className: 'aiseo-modal',
                            style: { maxWidth: '600px' }
                        },
                        wp.element.createElement(
                            TextControl,
                            {
                                label: __('Primary Keyword', 'ai-seo-content-generator'),
                                help: __('Enter the main SEO keyword for the content.', 'ai-seo-content-generator'),
                                value: keywords,
                                onChange: setKeywords
                            }
                        ),
                        wp.element.createElement(
                            TextareaControl,
                            {
                                label: __('Content Prompt', 'ai-seo-content-generator'),
                                help: __('Describe the content you want, e.g., "Write a blog post about sustainable fashion."', 'ai-seo-content-generator'),
                                value: prompt,
                                onChange: setPrompt
                            }
                        ),
                        wp.element.createElement(
                            TextControl,
                            {
                                label: __('Word Count', 'ai-seo-content-generator'),
                                help: __('Approximate number of words for the content.', 'ai-seo-content-generator'),
                                type: 'number',
                                value: length,
                                onChange: setLength
                            }
                        ),
                        wp.element.createElement(
                            SelectControl,
                            {
                                label: __('Tone', 'ai-seo-content-generator'),
                                help: __('Select the tone for the content.', 'ai-seo-content-generator'),
                                value: tone,
                                options: [
                                    { label: 'Neutral', value: 'neutral' },
                                    { label: 'Informative', value: 'informative' },
                                    { label: 'Storytelling', value: 'storytelling' },
                                    { label: 'Professional', value: 'professional' },
                                    { label: 'Friendly', value: 'friendly' },
                                    { label: 'Humorous', value: 'humorous' }
                                ],
                                onChange: setTone
                            }
                        ),
                        wp.element.createElement(
                            SelectControl,
                            {
                                label: __('Language', 'ai-seo-content-generator'),
                                help: __('Select the language for the content.', 'ai-seo-content-generator'),
                                value: language,
                                options: [
                                    { label: 'Vietnamese', value: 'vi' },
                                    { label: 'English', value: 'en' },
                                    { label: 'Korean', value: 'ko' }
                                ],
                                onChange: setLanguage
                            }
                        ),
                        wp.element.createElement(
                            SelectControl,
                            {
                                label: __('AI Model', 'ai-seo-content-generator'),
                                help: __('Select the AI model to generate content.', 'ai-seo-content-generator'),
                                value: api,
                                options: [
                                    { label: 'Google Gemini (Studio Licensed)', value: 'gemini-studio' },
                                    { label: 'Google Gemini (3 Flash)', value: 'gemini-3-flash' },
                                    { label: 'Google Gemini (2.0 Flash)', value: 'gemini-2.0' },
                                    { label: 'Google Gemini (1.5 Flash)', value: 'gemini-1.5' },
                                    { label: 'DeepSeek (V3)', value: 'deepseek' }
                                ],
                                onChange: setApi
                            }
                        ),
                        wp.element.createElement(
                            CheckboxControl,
                            {
                                label: __('Auto-fill post title', 'ai-seo-content-generator'),
                                help: __('Automatically set the generated SEO title as the post title (only if current title is empty).', 'ai-seo-content-generator'),
                                checked: autoFillTitle,
                                onChange: setAutoFillTitle
                            }
                        ),
                        wp.element.createElement(
                            Button,
                            {
                                isPrimary: true,
                                isBusy: isLoading,
                                disabled: isLoading,
                                onClick: handleAIRequest,
                                style: { marginTop: '20px' }
                            },
                            isLoading ? __('Generating...', 'ai-seo-content-generator') : __('Generate Content', 'ai-seo-content-generator')
                        )
                    )
                )
            );
        }
    });
})(window.wp);