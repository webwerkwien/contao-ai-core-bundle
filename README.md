# contao-ai-core-bundle

Contao 5 bundle that exposes CMS operations as Symfony console commands. The agnostic operator layer for the **contao-ai** family — programmatic read and write access to pages, articles, news, files, members, and more, without any LLM dependency.

> **Beta software.** Bundle interfaces (command names/options, JSON output schema) may change between minor versions. Use at your own risk in production.

## The contao-ai ecosystem

| Package | What it is | When to use |
|---|---|---|
| **contao-ai-core-bundle** *(this package)* | Contao bundle exposing CMS operations as Symfony console commands. | Required as the foundation layer. Install on any Contao site you want to manage via AI. |
| [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) | Python CLI — connects to Contao via SSH and runs commands. | For developers and agencies: manage Contao from the terminal or hand control to an AI agent. |
| [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle) | Contao backend module — browser-based AI chat interface (Anthropic Claude, OpenAI). | For editors and admins: AI directly inside the Contao backend, no SSH or terminal needed. |

## What it does

contao-ai-core-bundle exposes Contao 5 CMS operations as Symfony console commands. It is the bridge layer between AI agents and the Contao CMS — used via SSH by [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli), or called in-process by [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle).

## Requirements

- PHP ^8.2
- Contao ^5.3

## Installation

```bash
composer require webwerkwien/contao-ai-core-bundle
```

## Available Commands

All commands output JSON and follow a consistent `{"status":"ok", ...}` / `{"status":"error", ...}` format.

| Area | Commands |
|---|---|
| Pages | `contao:page:read` `contao:page:create` `contao:page:update` `contao:page:delete` `contao:page:publish` |
| Articles | `contao:article:read` `contao:article:create` `contao:article:update` `contao:article:delete` |
| Content elements | `contao:content:read` `contao:content:create` `contao:content:update` `contao:content:delete` |
| News | `contao:news:read` `contao:news:create` `contao:news:update` `contao:news:delete` |
| Events | `contao:event:read` `contao:event:create` `contao:event:update` `contao:event:delete` |
| FAQ | `contao:faq:read` `contao:faq:create` `contao:faq:update` `contao:faq:delete` |
| Members | `contao:member:update` `contao:member:delete` |
| Users | `contao:user:update` `contao:user:delete` |
| Files | `contao:file:read` `contao:file:write` `contao:file:meta` `contao:file:process` `contao:folder:create` |
| Templates | `contao:template:list` `contao:template:read` `contao:template:write` |
| Comments | `contao:comment:delete` `contao:comment:publish` |
| Layout | `contao:layout:read` |
| Versions | `contao:version:list` `contao:version:read` `contao:version:create` `contao:version:restore` |
| Search | `contao:search:query` |
| Schema / Config | `contao:dca:schema` `contao:listing:config` |
| Macros (since v0.2.0) | `contao:record:list` `contao:record:clone` |

### Macro commands

- `contao:record:list <table>` — table-agnostic listing with Doctrine-parameterised filters, DCA-validated ORDER BY, pagination, curated default columns per table.
- `contao:record:clone <table> <id> [--recursive]` — clone a container record (news archive, calendar, FAQ category, page) including all cascading children in one DB transaction. With `--recursive` PageCloner walks subpage trees (depth-cap 10, total-cap 50). Tagged-iterator EntityCloner registry — only registers cloners for plugins actually installed on the target site.

> **`record_rewrite` lives in [contao-ai-backend-bundle](https://github.com/webwerkwien/contao-ai-backend-bundle), not here.** That command needs an LLM API key per call. Keeping core agnostic of LLM dependencies and key handling was a deliberate architecture decision.

## License

MIT — see [LICENSE](LICENSE).

This software is provided "as is", without warranty of any kind. The authors accept no liability for any damages arising from its use.
