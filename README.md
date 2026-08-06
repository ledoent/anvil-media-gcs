# anvil-media-gcs

**Keyless WordPress media offload to Google Cloud Storage.** Authenticates through Workload Identity / Application Default Credentials — no service-account JSON key, no HMAC key, nothing to rotate or leak.

---

## Why this exists

Every other WordPress → GCS offload plugin requires a static credential:

| Plugin | Credential |
|---|---|
| S3-Uploads | HMAC access key + secret |
| WP-Stateless | service-account JSON key |
| Media Cloud | service-account JSON key |
| WP Offload Media | JSON key, or a paid tier for server roles |

Google **enforces `constraints/iam.disableServiceAccountKeyCreation` by default for organizations created on or after 2024-05-03**, and — this is the part that surprises people — **that constraint blocks Cloud Storage HMAC keys too**, not just JSON keys:

```console
$ gcloud storage hmac create sa@project.iam.gserviceaccount.com
ERROR: HTTPError 412: Request violates constraint
       'constraints/iam.disableServiceAccountKeyCreation'
```

It isn't obvious from the policy list, because `storage.restrictHmacKeyCreation` can be unset and HMAC creation *still* fails. The practical result is that a growing population of GCP organizations cannot use any mainstream media-offload plugin as documented.

This plugin passes **no credentials at all** and lets the Google SDK resolve Application Default Credentials, which on GKE means the metadata server and Workload Identity.

## Status

Working and verified against live GCS on GKE. Pre-1.0 — the API may still move.

## Requirements

- PHP 8.1+
- WordPress 6.7+ (uses `pre_attachment_url_to_postid`, added in 6.7)
- A GCS bucket, and a workload with an identity holding `roles/storage.objectAdmin` **on that bucket only**

## Install

```console
composer require ledoent/anvil-media-gcs
```

Register the stream wrapper in **`wp-config.php`, before WordPress loads** — not from plugin scope, or WordPress will have touched the filesystem first:

```php
require_once __DIR__ . '/vendor/autoload.php';

$anvil = new Ledoent\AnvilMediaGcs\Storage( 'your-bucket', 'your-project' );
$anvil->register_stream_wrapper();

( new Ledoent\AnvilMediaGcs\Plugin(
    $anvil,
    'https://storage.googleapis.com/your-bucket'   // or your CDN host
) )->boot();

define( 'FS_METHOD', 'direct' );
```

`FS_METHOD` is not optional. `get_filesystem_method()` compares `fileowner()` of a probe file against the plugin directory's owner; a stream reports `uid=0`, the comparison fails, and WordPress falls through to `ssh2`/`ftpext` and prompts for FTP credentials.

### GKE Workload Identity

```console
gcloud iam service-accounts create wp-media --project=PROJECT

gcloud storage buckets add-iam-policy-binding gs://YOUR_BUCKET \
  --member="serviceAccount:wp-media@PROJECT.iam.gserviceaccount.com" \
  --role=roles/storage.objectAdmin

gcloud iam service-accounts add-iam-policy-binding \
  wp-media@PROJECT.iam.gserviceaccount.com \
  --role=roles/iam.workloadIdentityUser \
  --member="serviceAccount:PROJECT.svc.id.goog[NAMESPACE/wp-media]"

kubectl annotate serviceaccount wp-media -n NAMESPACE \
  iam.gke.io/gcp-service-account=wp-media@PROJECT.iam.gserviceaccount.com
```

Grant `objectAdmin` on the **bucket**, not the project. Note it does not include `storage.buckets.get` — deliberately. This plugin never calls `Bucket::info()`.

## Migration is an `rsync`

`_wp_attached_file` stores paths **relative to the uploads basedir**, and this plugin preserves the `YYYY/MM/` layout verbatim. So migrating an existing library needs **no database rewrite**:

```console
gcloud storage rsync -r wp-content/uploads gs://YOUR_BUCKET
```

That is also why `srcset` keeps working. `wp_calculate_image_srcset()` substring-tests the rendered URL against `"{$dirname}{$file}"` and, on a mismatch, **silently returns `false` and drops srcset entirely** — no warning, no partial output. Plugins that flatten or hash object keys cause exactly that bug. Preserving layout avoids it structurally.

## Reversible by design

URLs are filtered at render time. **The database is never rewritten.** Deactivating the plugin restores local behaviour with no migration step and nothing to repair.

## The `url_stat` problem

The Google SDK's own stream wrapper costs **three API calls per stat**, with no caching:

1. `objects(['prefix' => …, 'resultLimit' => 1])` — a LIST
2. `$object->info()` — a GET
3. `$bucket->isWritable()` → `getResumableUploader()->getResumeUri()` — **a live POST opening a resumable upload session**, on every stat, purely to read a mode bit that is then discarded

WordPress stats constantly: `wp_mkdir_p()` on every `wp_upload_dir()`, the `wp_unique_filename()` collision loop, `_load_image_to_edit_path()`, the image-edit suffix loop.

`Ledoent\AnvilMediaGcs\StreamWrapper` subclasses `url_stat()` with a bounded per-request LRU and a static mode template. Measured on GKE against live GCS, ten distinct-path stats:

```
498 ms  →  0 ms
```

PHP's own stat cache only helps for repeats of the *same* path. Collision loops generate *distinct* paths, which is precisely the case it does not cover.

## What it does

| Hook | Why |
|---|---|
| `upload_dir` | Point uploads at `gs://bucket`, layout preserved |
| `wp_get_attachment_url` | Serve from the bucket or CDN |
| `wp_read_image_metadata` | Copy to a local temp file first — `exif_read_data()` and `iptcparse()` are C-level and can never read a PHP stream |
| `pre_wp_unique_filename_file_list` | One prefix query instead of a `scandir()` that enumerates the entire uploads prefix |
| `pre_attachment_url_to_postid` | Core's exact-string match fails behind a CDN host |
| `pre_get_space_used` | Computing it means walking the whole bucket |

## What it is not

**This is for sites you deploy, not sites you administer through wp-admin.** It pairs with an immutable image where plugins and themes are baked in and `DISALLOW_FILE_MODS` is set. If you need to install plugins by clicking, this is the wrong tool.

Not yet supported: multisite (untested), signed URLs for private media, S3/R2/Azure.

## Prior art

- [`mrhenry/gs-uploads`](https://github.com/mrhenry/gs-uploads) — MIT, the only other project on `google/cloud-storage` v2, and the source of this design's shape
- [`GoogleCloudPlatform/wordpress-plugins`](https://github.com/GoogleCloudPlatform/wordpress-plugins) — Google's own, archived 2020. Read it for the reasoning
- [`humanmade/S3-Uploads`](https://github.com/humanmade/S3-Uploads) — the mature S3 equivalent. Do not point it at GCS: `putObjectAcl` returns 400 unconditionally against a uniform-bucket-level-access bucket, including for `private`, and presigned URLs always require HMAC key material

## Licence

GPL-2.0-or-later.
