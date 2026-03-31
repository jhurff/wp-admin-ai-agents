# WP Admin for AI Agents

**Contributors:** Jimmy Hurff <jhurff@fastbytes.io>
**Tags:** rest-api, ai-agent, mcp, model-context-protocol, wordpress-admin, custom-fields, headless-wp
**Requires at least:** 5.0
**Tested up to:** 6.8
**Requires PHP:** 7.4
**Stable tag:** 2.0.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

AI agent-optimized WordPress admin plugin. Manage posts, pages, custom post types, and meta fields via REST API with API key authentication. MCP tool-ready for autonomous AI agents.

## Description

**WP Admin for AI Agents** gives AI agents like me (and Claude, ChatGPT, AutoGPT, LangChain agents, n8n workflows, and any autonomous agent) full access to WordPress without needing OAuth flows, browser sessions, or Application Passwords.

**One API key. Full WordPress access.**

### MCP Compatible

This plugin is designed with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) in mind:

- **Stateless authentication** - API keys work across sessions, no OAuth dance
- **Tool-friendly endpoints** - Each endpoint maps to a specific action
- **Structured JSON responses** - Easy for AI parsers
- **No HTML/scraping required** - Direct data access

**MCP Tool Definitions:**
```json
{
  "name": "wordpress_update_post_meta",
  "description": "Update meta field on any WordPress post or CPT",
  "inputSchema": {
    "type": "object",
    "properties": {
      "post_id": {"type": "number"},
      "meta_key": {"type": "string"},
      "meta_value": {"type": "string"}
    }
  }
}
```

### What AI Agents Can Do

* **Read/Write Posts** - Create, update, publish posts and pages
* **Manage CPT** - Handle custom post types (portfolios, products, listings)
* **CRUD Meta Fields** - Update custom fields without duplicates
* **Price Tracking** - Built-in endpoint for stock/crypto price history
* **Bulk Operations** - Update multiple fields in one request
* **Admin UI** - View all meta fields (including `_` prefixed) in WordPress admin

### Why This Plugin Exists

**Problem 1: WordPress XML-RPC Creates Duplicate Meta**

When you update a meta field via WordPress XML-RPC, it creates a NEW entry instead of updating the existing one. After a few updates:
```
_meta_id = 1 | _price = 6.44 (original)
_meta_id = 2 | _price = 5.60 (duplicate #1)  
_meta_id = 3 | _price = 5.55 (duplicate #2)
```

This plugin uses WordPress's native `update_post_meta()` which properly updates in-place.

**Problem 2: AI Agents Can't Use Application Passwords**

WordPress 5.6+ has Application Passwords, but:
- Requires interactive OAuth-like flow
- Some hosts block it (Hostinger does)
- Not stateless - agents need browser cookies

This plugin uses simple API key auth that works everywhere.

**Problem 3: No MCP Tool Definitions**

AI agents need structured, discoverable tools. This plugin provides MCP-compatible endpoints with clear input/output schemas.

## Quick Start for AI Agents

### 1. Install & Activate

Upload to `/wp-content/plugins/` and activate in WordPress admin.

### 2. Generate API Key

As admin, go to **Tools → WP Admin for AI Agents** → Generate New Key

### 3. Start Using

```bash
# Health check
curl -H "X-API-Key: mm_live_xxx" \
  https://yoursite.com/wp-json/wp-ai/v1/health

# Get post meta
curl -H "X-API-Key: mm_live_xxx" \
  https://yoursite.com/wp-json/wp-ai/v1/get/123

# Update meta
curl -X POST -H "X-API-Key: mm_live_xxx" \
  -H "Content-Type: application/json" \
  -d '{"post_id":123,"meta_key":"_price","meta_value":"29.99"}' \
  https://yoursite.com/wp-json/wp-ai/v1/update
```

## MCP Integration

### Claude Desktop / Code Assistant

Add to your MCP settings:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-wordpress"],
      "env": {
        "WP_URL": "https://yoursite.com",
        "WP_API_KEY": "mm_live_xxx"
      }
    }
  }
}
```

### n8n Workflow Integration

Use HTTP Request node with:
- Method: POST
- URL: `https://yoursite.com/wp-json/wp-ai/v1/update`
- Headers: `X-API-Key: mm_live_xxx`

### Python AI Agent Example

```python
import requests

WP_URL = "https://yoursite.com"
API_KEY = "mm_live_xxx"

def update_wordpress_meta(post_id, key, value):
    response = requests.post(
        f"{WP_URL}/wp-json/wp-ai/v1/update",
        headers={"X-API-Key": API_KEY},
        json={"post_id": post_id, "meta_key": key, "meta_value": value}
    )
    return response.json()

# Update stock price
update_wordpress_meta(363, "_ws_last_price", "5.60")
```

## API Endpoints

### Health Check
```
GET /wp-json/wp-ai/v1/health
```
No authentication required.

### Get Post Meta
```
GET /wp-json/wp-ai/v1/get/{post_id}
```
Returns all meta fields for a post.

### Update Meta Field
```
POST /wp-json/wp-ai/v1/update
{
  "post_id": 123,
  "meta_key": "_price",
  "meta_value": "29.99"
}
```

### Bulk Update
```
POST /wp-json/wp-ai/v1/bulk-update
{
  "post_id": 123,
  "meta": {
    "_price": "29.99",
    "_sale_price": "24.99",
    "_stock": "100"
  }
}
```

### Price History (Stock/Crypto Tracking)
```
POST /wp-json/wp-ai/v1/update-history
{
  "post_id": 363,
  "price": 5.60,
  "change": 0.15,
  "change_pct": 2.75,
  "date": "2026-03-31"
}
```
Convenience endpoint that auto-updates `_ws_last_price`, `_ws_last_updated`, and appends to `_ws_price_history` array.

### Update Post Content
```
POST /wp-json/wp-ai/v1/update-post
{
  "post_id": 123,
  "title": "New Title",
  "content": "Post content here...",
  "status": "publish"
}
```

### Generate API Key (Admin)
```
POST /wp-json/wp-ai/v1/keys
{
  "name": "Claude Desktop",
  "scopes": ["read", "write"]
}
```

### List/Delete API Keys (Admin)
```
GET  /wp-json/wp-ai/v1/keys
DELETE /wp-json/wp-ai/v1/keys/{key_id}
```

## Authentication

**API Key Format:** `mm_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

Include in every request:
```
X-API-Key: mm_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Security Features:**
- Keys stored SHA256 hashed (raw key never stored)
- Scope-based permissions (read, write, admin)
- Usage tracking (last used, count)
- Instant revocation
- Rate limit ready

## Installation

1. Upload `wp-admin-ai-agents/` to `/wp-content/plugins/`
2. Activate plugin
3. Go to **Tools → WP Admin for AI Agents**
4. Generate your first API key
5. Start building!

## Frequently Asked Questions

### How is this different from WP REST API?

WordPress REST API exists, but:
- Requires WordPress login cookies or OAuth
- Can't update meta fields properly (creates duplicates)
- No API key management
- Not MCP tool-ready

This plugin adds proper meta field handling and stateless API key auth.

### Can I use this with WooCommerce?

Yes! Works with any post type including products. Update `_price`, `_stock`, `_sku` meta fields directly.

### What about security?

- API keys are one-way hashed (like passwords)
- Keys can have scopes (read-only, write, admin)
- All activity is trackable
- Revoke keys instantly
- Runs on your server - no third party involved

### Does this work with headless WordPress?

Perfectly! Use it as the backend for:
- Mobile apps
- Static site generators
- External AI agents
- n8n/ Zapier automations

## Changelog

### 2.0.0
- **Renamed** from Meta Manager to WP Admin for AI Agents
- Added MCP-compatible tool definitions
- API key authentication (stateless, agent-friendly)
- Scope-based permissions (read, write, admin)
- Full admin UI for key management
- Usage tracking per key

### 1.0.0
- Initial release
- Meta CRUD via REST
- Duplicate meta prevention
- Price history endpoint
- Admin meta box UI
