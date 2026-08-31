# Ecommerce: Abandoned Checkout Filament

> This optional Filament 5 presentation package presents exactly one independent domain module. It contributes reusable resources, pages, widgets, schemas, tables, infolists, and actions to application-owned panels while delegating authorization, validation, tenancy, persistence, and business rules to the ecommerce-abandoned-checkout public boundar

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-abandoned-checkout-filament?sort=semver)](https://github.com/liberusoftware/module-ecommerce-abandoned-checkout-filament/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-abandoned-checkout-filament/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-abandoned-checkout-filament/actions/workflows/tests.yml)

## What it presents

The operator's view of `liberusoftware/ecommerce-abandoned-checkout`: a checkout that was begun and
not finished, the campaigns that decide whether to reach out about it, every attempt made, and what
came of each.

**The one fact that shaped it.** Every end of this pipeline is unattached until a host attaches it.
Nothing carries a message and nothing builds the link a message would contain, so an action that
needs an unbound seam records a refusal with a reason and returns. A merchant looking at this panel
on a fresh install must see *why* nothing has been sent — `NoChannelBound` is the truthful answer and
it is not an error. Every screen here renders the refusal; none of them renders an empty table that
reads as "nothing to do".

| Screen | What it answers |
|---|---|
| **Outreach** | Whether anything can go out at all: which seam is unbound, what has been recorded, what has been attempted, every refusal by reason, and whether a bound channel reports anything back |
| **Abandonments** | What was left, as it was stored — never re-resolved against a catalogue that has since been repriced — with the state derived from one clock and the attempts against it |
| **Campaigns** | When to reach out, through which key, how often at most, and under what conditions, with whether anything is bound to carry it |
| **Outreach standing** (widget) | One line per seam and per refusal reason, so the cause is on the first screen rather than inferred from an absence |
| **Due now** (widget) | The abandonments the domain says are eligible under a campaign at this instant — which is not a promise that anything will go |

## What it does not own

No business rule, no arithmetic, no transport, and no decision. Every write is a published domain
action taking the tenant as its first argument. There is no create, edit or delete anywhere; no
control that records an outcome, because an outcome is an observation and a button is not one; and
no control that spans more than one merchant.

`docs/panel.md` carries the decisions and the gaps found in the domain package.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

To install this package via Composer, run:

```bash
composer require liberusoftware/module-ecommerce-abandoned-checkout-filament
```

## Documentation

- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-abandoned-checkout-filament/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-abandoned-checkout-filament" alt="Contributors to liberusoftware/module-ecommerce-abandoned-checkout-filament">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-abandoned-checkout-filament/graphs/contributors).
