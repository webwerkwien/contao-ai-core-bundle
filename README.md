# contao-ai-core-bundle

> ⚠️ **Beta Software / Beta-Software**
> This bundle is under active development. Commands and interfaces may change without notice. Use at your own risk. Not recommended for production environments without thorough testing.
>
> Dieses Bundle befindet sich in aktiver Entwicklung. Befehle und Schnittstellen können sich ohne Vorankündigung ändern. Nutzung auf eigene Gefahr. Nicht für Produktivumgebungen ohne ausführliche Tests empfohlen.

---

## The contao-ai ecosystem / Das contao-ai-Ökosystem

This package is part of the **contao-ai** family — a set of tools for AI-assisted Contao 5 management.

Dieses Paket ist Teil der **contao-ai**-Familie — einer Sammlung von Werkzeugen für die KI-gestützte Verwaltung von Contao 5.

| Package | What it is / Was es ist | When to use / Wann verwenden |
|---|---|---|
| **contao-ai-core-bundle** *(this package)* | Contao bundle — exposes CMS operations as Symfony console commands / Contao-Bundle — stellt CMS-Operationen als Symfony-Console-Commands bereit | Required as the foundation layer. Install on any Contao site you want to manage via AI. / Wird als Grundlage benötigt. Auf jeder Contao-Seite installieren, die KI-gesteuert verwaltet werden soll. |
| [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) | Python CLI — connects to Contao via SSH and runs commands / Python-CLI — verbindet sich via SSH mit Contao und führt Commands aus | For developers and agencies: manage Contao from the terminal or hand control to an AI agent. / Für Entwickler und Agenturen: Contao vom Terminal aus verwalten oder die Kontrolle an einen KI-Agenten übergeben. |
| contao-ai-backend-bundle *(planned / geplant)* | Contao backend module — browser-based AI chat interface with support for multiple AI providers (Anthropic Claude, OpenAI, and more) / Contao-Backend-Modul — browser-basierte KI-Chat-Oberfläche mit Unterstützung für mehrere KI-Anbieter (Anthropic Claude, OpenAI u.a.) | For end users and editors: use AI directly inside the Contao backend, no SSH or terminal needed. / Für Redakteure und Endnutzer: KI direkt im Contao-Backend nutzen, ohne SSH oder Terminal. |

---

## English

### What it does

contao-ai-core-bundle is a Contao 5 bundle that exposes CMS operations as Symfony console commands. It serves as the bridge layer between AI agents and the Contao CMS — enabling programmatic read and write access to pages, articles, news, files, members, and more.

It is designed to be used via SSH by [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli), or called in-process by a backend module.

### Requirements

- PHP ^8.2
- Contao ^5.3

### Installation

```bash
composer require webwerkwien/contao-ai-core-bundle
```

### Available Commands

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

### License

MIT — see [LICENSE](LICENSE).

This software is provided "as is", without warranty of any kind. The authors accept no liability for any damages arising from its use.

---

## Deutsch

### Was es macht

contao-ai-core-bundle ist ein Contao-5-Bundle, das CMS-Operationen als Symfony-Console-Commands bereitstellt. Es dient als Verbindungsschicht zwischen KI-Agenten und dem Contao-CMS — und ermöglicht programmatischen Lese- und Schreibzugriff auf Seiten, Artikel, News, Dateien, Mitglieder und mehr.

Es ist für die Nutzung via SSH durch [contao-ai-cli](https://github.com/webwerkwien/contao-ai-cli) konzipiert oder kann direkt von einem Backend-Modul aufgerufen werden.

### Voraussetzungen

- PHP ^8.2
- Contao ^5.3

### Installation

```bash
composer require webwerkwien/contao-ai-core-bundle
```

### Verfügbare Befehle

Alle Befehle geben JSON aus und folgen einem einheitlichen Format: `{"status":"ok", ...}` / `{"status":"error", ...}`.

Siehe Tabelle im englischen Abschnitt.

### Lizenz

MIT — siehe [LICENSE](LICENSE).

Diese Software wird ohne jegliche Gewährleistung bereitgestellt. Die Autoren übernehmen keine Haftung für Schäden, die aus der Nutzung entstehen.
