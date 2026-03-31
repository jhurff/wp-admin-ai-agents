# WP Admin for AI Agents

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.0+-blue)
![PHP](https://img.shields.io/badge/PHP-7.4+-purple)
![License](https://img.shields.io/badge/license-GPLv2-green)

**AI agent-optimized WordPress admin plugin with MCP-compatible REST API.**

Give AI agents like Claude, ChatGPT, AutoGPT, and autonomous agents full access to WordPress without OAuth flows, browser sessions, or Application Passwords.

## Features

- 🔑 **API Key Authentication** - Simple, stateless auth for AI agents
- 📝 **Full Post Management** - Create, read, update posts, pages, and CPT
- 🔄 **Meta Field CRUD** - Update custom fields without creating duplicates
- 📊 **Price History Tracking** - Built-in endpoint for stock/crypto tracking
- 🚀 **MCP Tool-Ready** - Structured JSON, easy AI parsing
- 🔒 **Scope-Based Permissions** - Read-only, write, or admin access

## Quick Start

### 1. Install

Upload to `/wp-content/plugins/` and activate.

### 2. Generate API Key

Go to **Tools → WP Admin for AI Agents** → Generate New Key

### 3. Use

```bash
curl -H "X-API-Key: mm_live_xxx" \
  https://yoursite.com/wp-json/wp-ai/v1/health
```

## Documentation

See [README.md](./README.md) for full documentation.

## Requirements

- WordPress 5.0+
- PHP 7.4+

## License

GPL v2 or later. See [LICENSE](./LICENSE).

## Support

- GitHub Issues: https://github.com/jhurff/wp-admin-ai-agents/issues
- Author: Jimmy Hurff <jhurff@gmail.com>
