---
title: Magic Authentication
order: 5
---

# Sharing sites with magic authentication

Expose allows you to protect your shared sites with a simple email-based authentication flow called "magic authentication". Instead of a browser popup asking for credentials, visitors see a clean login form where they enter their email address. Once submitted, a secure cookie is set allowing access on subsequent requests.

This provides a more user-friendly authentication experience compared to basic authentication, while still providing security for your shared sites.

## Using magic authentication

To share your site with magic authentication that accepts any email address, use the `--magic-auth` flag:

```bash
expose share my-site.test --magic-auth
```

When someone visits your shared URL, they'll see a login form asking for their email address. After entering a valid email, they'll be redirected to the original page and can browse freely for the next 7 days.

## Restricting access by email domain

You can restrict access to specific email domains using the `@domain.com` pattern:

```bash
# Only allow emails from @company.com
expose share my-site.test --magic-auth=@company.com
```

This is useful when sharing development sites with your team - only team members with company email addresses can access the site.

## Restricting access to multiple domains

Separate multiple patterns with commas:

```bash
# Allow emails from @company.com and @partner.com
expose share my-site.test --magic-auth=@company.com,@partner.com
```

## Allowing specific email addresses

You can also allow specific email addresses:

```bash
# Only allow these specific users
expose share my-site.test --magic-auth=alice@example.com,bob@example.com
```

## Combining domains and specific emails

Mix domain patterns and specific email addresses as needed:

```bash
# Allow anyone from @company.com plus a specific contractor
expose share my-site.test --magic-auth=@company.com,contractor@external.com
```

## How it works

1. When a visitor accesses your shared URL without a valid authentication cookie, they see a login form
2. They enter their email address and submit the form
3. If the email matches the allowed patterns (or any email is allowed), a signed cookie is set
4. The visitor is redirected to the original page they requested
5. Future requests include the cookie and pass through to your local site
6. The authentication cookie expires after 7 days

## Connection status

When magic authentication is enabled, the connection table shows the current auth configuration:

```
┌────────────┬──────────────────────────────────────────────┐
│ Shared site│ my-site.test                                 │
│ Dashboard  │ http://127.0.0.1:4040                        │
│ Public URL │ https://my-site.sharedwithexpose.com         │
│ Magic Auth │ Enabled (@company.com)                       │
└────────────┴──────────────────────────────────────────────┘
```

## Combining with other options

Magic authentication works with other sharing options:

```bash
# Custom subdomain with magic auth
expose share my-site.test --subdomain=demo --magic-auth=@company.com

# With custom domain
expose share my-site.test --domain=mycompany.com --magic-auth

# With QR code
expose share my-site.test --magic-auth --qr-code
```

> **Note**: Magic authentication cannot be combined with basic authentication (`--auth`). Choose one authentication method per share session.
