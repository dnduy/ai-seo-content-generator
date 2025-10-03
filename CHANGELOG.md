# Changelog

All notable changes to AI SEO Content Generator plugin will be documented in this file.

## [3.0.0] - 2025-10-03

### 🎉 Major Release - Complete Overhaul

### Added
- **🔐 Encrypted API Key Storage**: API keys are now encrypted using AES-256-CBC before storage
- **⚡ Smart Caching System**: 1-hour cache for API responses to improve performance
- **🔄 Multi-API Fallback**: Automatic fallback to alternative APIs when primary fails
- **📊 Content History**: Complete tracking of generated content with metadata
- **🌐 Internationalization**: Full i18n support with text domain
- **📱 Better UX**: Loading states, progress indicators, and improved error messages

### Enhanced
- **Content Parsing**: Improved HTML parsing logic with better block generation
- **Error Handling**: Comprehensive error handling with user-friendly messages
- **Security**: Nonce verification, input sanitization, and capability checks
- **Performance**: Optimized database queries and reduced API calls
- **Code Structure**: Better separation of concerns and modular architecture

### Fixed
- **Fatal Error**: Fixed `wp_salt()` undefined function error
- **Content Quality**: Better handling of malformed HTML and edge cases
- **Rate Limiting**: Proper enforcement of API rate limits
- **Memory Usage**: Optimized memory usage for large content generation

### Technical Improvements
- Database schema for content history
- Automated cleanup of old records
- Cache management interface
- Plugin activation/deactivation hooks
- Text domain loading for translations

## [2.3.0] - 2025-09-20

### Added
- Initial release with basic AI content generation
- Google Gemini and DeepSeek API integration
- WordPress Gutenberg editor integration
- Basic SEO guidance generation

### Features
- Generate SEO-optimized content
- Multiple AI model support
- Basic error handling
- Simple content insertion

## [2.0.0] - 2025-08-15

### Added
- Beta version with core functionality
- Gutenberg block integration
- API key management

## [1.0.0] - 2025-07-01

### Added
- Initial development version
- Basic proof of concept