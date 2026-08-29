# Changelog

## Unreleased
*   Feature: Share a Laravel app and its running Vite dev server (including HMR) under a single public hostname. The client now routes Vite requests to the dev server in-process instead of rewriting `public/hot` and spawning a second `expose share` process. The hot file is never modified anymore, killing the tunnel leaves nothing to clean up, and Vite sharing now also works on Windows.
*   Feature: Herd Studio works through shared URLs. When Herd's AI assistant is enabled, the client routes the Studio websocket (`/ai/ws`) to the local Herd app, so a shared `/__herd-ai__/studio` link is fully functional remotely. Local upstream routing is extensible via the `LocalUpstreamContract`.
*   Fix: Default ports are stripped from the forwarded `Host` header (`myapp.test:443` → `myapp.test`), so applications see the same `HTTP_HOST` as during local browsing, and redirects to the bare hostname are rewritten to the share URL.
*   Removed the `--no-vite-detection` option, as Vite detection no longer changes any local state.

## 1.3.0 (2020-07-01)
*   Feature: Add pagination to admin user interface
*   Feature: Add request time to CLI output
*   Feature: Add `X-Forwarded-Host` header
*   Fix: Fix remaining time calculation
*   Fix: Don't use underscores for automatic subdomain generation

## 1.1.0 (2020-06-18)
*   Feature: Allow overriding the subdomain when using `expose` without specifying `expose share` explicitly
*   Show badges in the local dashboard for 3xx response statuses
*   Fix: Updated minimum PHP dependency 
*   Fix: Added support for detecting the Windows user home path
*   Fix: Use minified VueJS versions
*   Various spelling fixes

## 1.0.1 (2020-06-17)
*   Fixes an issue when setting the auth token

## 1.0.0 (2020-06-17)
*   Initial release
