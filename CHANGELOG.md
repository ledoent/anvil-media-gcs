# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Keyless media offload to Google Cloud Storage, authenticating via Application
  Default Credentials — no service-account key, no HMAC key.
- Caching `url_stat()` subclass of the SDK stream wrapper. The SDK's own
  implementation costs three API calls per stat, one of which opens a resumable
  upload session purely to read a mode bit. Measured against live GCS on GKE, a
  ten-name collision loop drops from 498 ms to 0 ms.
- `apiEndpoint` override so the package can be tested against an emulator.
- Local test harness: WordPress, MariaDB and fake-gcs-server under Docker
  Compose, covering a clean install and a migration of pre-existing media.

### Notes
- Migration needs no database rewrite. `_wp_attached_file` stores paths relative
  to the uploads basedir and the `YYYY/MM` layout is preserved, so moving an
  existing library is `gcloud storage rsync`. Preserving that layout is also what
  keeps `wp_calculate_image_srcset()` matching — it silently drops srcset
  entirely on a mismatch.
