# Architecture: module-phpbrowser (Codeception)

## Purpose

The Codeception PhpBrowser module. Provides headless browser-like acceptance testing via Guzzle HTTP client — no JavaScript execution, but fast HTTP-level interaction with forms, links, cookies, and responses.

## Directory Structure

```
src/Codeception/
  Module/
    Php_Browser.php        — Codeception module: amOnPage, click, fillField, submitForm, see, etc.
  Lib/
    Connector/
      Guzzle.php           — BrowserKit-compatible Guzzle connector (HTTP client adapter)
```

## Key Design Decisions

- **BrowserKit integration**: `Guzzle` implements Symfony BrowserKit's `AbstractBrowser`, so Codeception's shared `InnerBrowser` trait handles cookie management, redirect following, and form submission uniformly
- **No JavaScript**: Requests are plain HTTP; JavaScript interactions require WebDriver instead
- **Guzzle middleware**: Custom Guzzle middleware can be attached for advanced scenarios (e.g., OAuth token injection)

## Extension Points

- Configure `handler` in the module config to inject a custom Guzzle handler (e.g., for mocking)
- Use `headers` config key to set default HTTP headers on all requests
