# AI SEO Content Generator

A powerful WordPress plugin that generates SEO-optimized content using Google Gemini or DeepSeek API in WordPress Gutenberg editor.

## 🚀 Features

### Core Features
- **Multi-AI Support**: Google Gemini (1.5 Flash, 2.0 Flash) and DeepSeek V3
- **SEO Optimization**: Auto-generate SEO titles, meta descriptions, and keywords
- **Gutenberg Integration**: Seamless integration with WordPress block editor
- **Content History**: Track and manage all generated content
- **Multi-language Support**: Vietnamese, English, Korean

### Advanced Features
- **🔐 Secure API Key Storage**: Encrypted API keys in database
- **⚡ Smart Caching**: 1-hour cache to reduce API calls and improve performance
- **🔄 Auto-Fallback**: Automatically switches to alternative APIs when one fails
- **📊 Content Analytics**: Word count, API usage tracking
- **🌐 Internationalization**: Ready for translations

## 📋 Requirements

- WordPress 6.0+
- PHP 7.4+
- OpenSSL extension (recommended for encryption)
- API keys from:
  - [Google AI Studio](https://ai.google.dev/) for Gemini
  - [OpenRouter](https://openrouter.ai/) for DeepSeek

## 🛠 Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-seo-content-generator/`
3. Activate the plugin through WordPress admin
4. Configure API keys in `Settings > AI SEO Content`

## ⚙️ Configuration

### API Keys Setup
1. Go to `Settings > AI SEO Content`
2. Enter your API keys:
   - **Gemini API Key**: Get from [Google AI Studio](https://ai.google.dev/)
   - **DeepSeek API Key**: Get from [OpenRouter](https://openrouter.ai/)
3. Keys are automatically encrypted before storage

### Cache Management
- Content is cached for 1 hour by default
- Use "Clear Cache" button to force refresh
- Automatic cleanup of old cache entries

## 📝 Usage

### Generating Content
1. Open WordPress post/page editor (Gutenberg)
2. Look for "Generate SEO Content" button in the post status panel
3. Fill in the form:
   - **Primary Keyword**: Main SEO keyword
   - **Content Prompt**: Describe what you want
   - **Word Count**: Approximate length
   - **Tone**: Select writing style
   - **Language**: Choose output language
   - **AI Model**: Pick preferred AI

### Content History
- View all generated content in `Posts > AI Content History`
- Browse by date, keywords, and API used
- Access full content and SEO metadata

## 🏗 Plugin Structure

```
ai-seo-content-generator/
├── ai-seo-content-generator.php    # Main plugin file
├── assets/
│   ├── css/
│   │   └── style.css               # Plugin styles
│   └── js/
│       └── block-editor.js         # Gutenberg integration
├── includes/
│   ├── api-handler.php             # API communication
│   └── database.php                # Database operations
├── languages/                      # Translation files
└── README.md                       # This file
```

## 🔧 Technical Features

### Security
- API keys encrypted with AES-256-CBC
- Nonce verification for all requests
- Input sanitization and validation
- Capability checks for user permissions

### Performance
- WordPress transients for caching
- Rate limiting (10 seconds between requests)
- Optimized database queries
- Lazy loading of components

### Error Handling
- Multi-API fallback system
- Comprehensive error logging
- User-friendly error messages
- Graceful degradation

## 🎨 Customization

### Supported Content Types
- Headings (H2-H6)
- Paragraphs
- Unordered lists
- Ordered lists
- Rich text formatting

### Available Tones
- Neutral
- Informative
- Storytelling
- Professional
- Friendly
- Humorous

## 🔄 API Fallback Logic

1. **Primary**: User-selected API
2. **Secondary**: Alternative APIs in order
3. **Fallback**: Automatic retry with different models
4. **Error Handling**: Clear user feedback on failures

## 📊 Version History

### v3.0 (Latest)
- ✅ Encrypted API key storage
- ✅ Content caching system
- ✅ Multi-API fallback
- ✅ Content history tracking
- ✅ Improved internationalization
- ✅ Better content parsing

### v2.3
- Basic AI content generation
- Gutenberg integration
- SEO guidance

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

GPL2 - Same as WordPress

## 🆘 Support

For issues and support:
1. Check the [Issues](../../issues) page
2. Review documentation
3. Contact plugin author

## 🔮 Roadmap

- [ ] Custom prompts templates
- [ ] Bulk content generation
- [ ] WordPress Multisite support
- [ ] Integration with popular SEO plugins
- [ ] Content quality scoring
- [ ] A/B testing for content variations

---

**Made with ❤️ for the WordPress community**