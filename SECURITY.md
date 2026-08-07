# Security Policy

## Supported versions

Pre-1.0. Only the latest release receives fixes.

## Reporting a vulnerability

Please report privately via [GitHub Security Advisories](https://github.com/ledoent/anvil-media-gcs/security/advisories/new)
rather than opening a public issue.

Expect an acknowledgement within a week.

## Scope notes

This package is designed to hold **no credentials**. It authenticates through
Application Default Credentials, so on GKE the identity comes from the metadata
server via Workload Identity and nothing is stored on disk or in the database.

A report that this package requires a service-account key or an HMAC key is a
misconfiguration rather than a vulnerability — but it is still worth raising as
an issue, because it means the documentation is unclear.
