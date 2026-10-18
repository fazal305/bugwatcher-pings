# Security Policy

## Reporting a vulnerability

Please report security issues privately by email to **fazalabbas2002@gmail.com** rather than opening a public issue. Include steps to reproduce and the affected version or commit.

You can expect an acknowledgement within 5 days. Once a fix is ready, it will be released and the report credited if you'd like.

## Scope

In scope: the Laravel API (`api/`) and the React app (`web/`). Please only test against your own local copy — do not scan or load-test any hosted instance.

## What's already in place

- API errors are returned as generic JSON in production (`APP_DEBUG=false`); stack traces and exception details are never sent to clients
- CORS limited to the configured web app origin(s) (`FRONTEND_URL`)
- The web app's Content-Security-Policy limits network access to the API and the Pusher endpoints
- No secrets are baked into the Docker image; they come from the host's environment at runtime
