# RackTables Quick Photo

A [RackTables](https://www.racktables.org/) plugin for adding and retaking photos from a phone.

It adds a **Photos** tab to locations, rows, racks and objects. The tab lists the image files
linked to the entity and has large touch-friendly buttons:

- **Add photo** opens the phone camera or photo library and attaches the new image to the entity.
- **Retake** replaces the contents of an existing photo with a new one (the file stays the same
  File record, so every place that links to it shows the new image).

The browser shrinks the picture before upload, so a 12 MP HEIC from an iPhone becomes a much smaller JPEG
(at most 4032 px on the longer side, about 2-4 MB). No RackTables core file is modified, and
the plugin has no database tables and no config variables.

## Prerequisites

- RackTables **0.22.0** (tested). It uses the plugin architecture of 0.22.
- A phone browser that can decode the photo: **iOS / Safari 17+** reads HEIC directly. On older
  iPhones set **Settings -> Camera -> Formats -> Most Compatible** so photos are taken as JPEG.
- The phone must reach RackTables over HTTPS or the local network, and must trust the server's
  certificate (for an internal CA, install and trust the CA on the phone).
- JavaScript must be enabled (see Limits).

## Installation

1. Copy the `quickphoto` directory into the RackTables `plugins/` directory:

   ```
   plugins/
     quickphoto/
       plugin.php
       lib.php
       css/quickphoto.css
       js/quickphoto.js
   ```

2. In RackTables, open **Configuration -> Plugins**, find **Quick Photo** and click **Install**.
3. Reload the page (hard refresh).

### RackCode

Access to the new tab and its operations is governed by RackCode like any other tab. If a user's
rules only allow `{$tab_default}`, the Photos tab stays hidden until you allow it, for example:

```
allow {$tab_photos}
allow {$op_addPhoto}
allow {$op_replacePhoto}
```

(adapt to your own rules, e.g. restrict with tags or user names). **Retake** additionally requires
the same permission as the core **Upload replacement** (page `file`, tab `replaceData`); without it the
Retake button is not shown and the server refuses the request. **Add photo** needs only the
entity-side permission (`{$tab_photos}` plus `{$op_addPhoto}`), not the core `addFile` permission.

To remove the plugin, click **Uninstall** in the same place.

## Usage

1. Open the entity (rack, object, ...) on the phone and go to the **Photos** tab.
2. **Add photo**: take a picture or pick one. A confirmation sheet shows a preview and its size, and
   lets you edit the **Name** (prefilled with `<entity name>_<date>`, e.g. `A-01_2026-11-24`) and type
   an optional comment. Confirm to upload. The name is cleaned up on the server (characters not allowed
   in file names become `-`, extra spaces are collapsed, at most 100 characters, `.jpg` is added). If
   the name is already taken, `_2`, `_3`, ... is appended. If you clear the field, the file is named
   automatically from the entity name and a timestamp, e.g. `rack-12_2026-11-24_101500.jpg`.
3. **Retake**: on an existing photo, take a new picture. The old content is overwritten.
4. **Shared photos**: if the image is also linked to other places, the confirmation sheet warns
   that replacing it changes it everywhere, and lists the other places you may view (others are
   only counted).

## Limits

- Pictures are scaled down so the longer side is at most **4032 px** (the full resolution of a 12 MP iPhone photo, so patch-panel labels stay readable;
  48 MP originals are downscaled to 4032 px, never upscaled) and re-encoded as **JPEG at
  quality 0.85**, typically 2-4 MB per photo. 4032x3024 is 12.2 MP, safely below the iOS Safari canvas limit (about 16.7 MP). The server accepts at most **8 MB** per photo and also respects `upload_max_filesize` and `post_max_size`.
- Re-encoding in the browser drops **EXIF / GPS** data. The server rejects uploads that still
  contain an Exif APP1 segment (EXIF/GPS), are not JPEG, exceed 4032 px or are malformed.
- Only JPEG, PNG and GIF files are listed and can be retaken (the types core can preview). A retaken
  file always becomes `image/jpeg`.
- JavaScript is required. Without it the tab shows a hint to use the regular Files tab.

## Security notes

- **CSRF token:** both write operations require a plugin-owned token stored in the PHP session.
- **Origin check:** requests with `Sec-Fetch-Site: cross-site`, or an `Origin` that does not match the
  request host, are rejected. This assumes the reverse proxy forwards the client `Host` header
  unchanged (including a non-default port).
- **Server-side validation:** size, JPEG type, dimensions and EXIF are checked on the server; the
  client is not trusted. Retake also checks that the file is linked to the current page and is an
  image type the plugin lists.
- **Permissions:** the core RackCode checks apply to the tab and ops; Retake and the file list also check
  the core file permissions (`replaceData`, `download`).
- **Transaction:** adding a photo (file plus link) and replacing one run in a single database
  transaction.
- **Concurrency check:** Retake locks the file row and refuses to overwrite if the file's
  modification time, size or link set changed since the tab was opened.

## How it works

`plugin.php` registers a `photos` tab and the `addPhoto` / `replacePhoto` operation handlers for the
`location`, `row`, `rack` and `object` realms. New photos are stored with core `commitAddFile()` and
linked to the entity; Retake uses core `commitReplaceFile()`. `js/quickphoto.js` (plain JavaScript,
no dependencies) decodes the chosen picture, scales it on a canvas, encodes it as JPEG, shows the
confirmation sheet and uploads it with `fetch`. After encoding, the client removes the APP1 and
APP13 metadata segments from the JPEG, because Apple's encoder (iOS Safari and Chrome) adds an Exif
segment even to canvas output, which the server would otherwise reject. A JPEG that cannot be parsed
is treated as an error and nothing is uploaded. `lib.php` holds the pure validation and naming helpers.

## Development and tests

The tests expect a local RackTables 0.22.0 dev instance (Docker) with the demo data loaded.
The integration script uses `md5 -q` on macOS and falls back to `md5sum` elsewhere.

- `tests/unit.php` - unit tests of the helpers in `lib.php`, run inside the dev web container.
- `tests/integration.sh` - HTTP-level tests of the tab and both operations against the dev instance.
- `tests/e2e.js` - browser tests with Playwright (`cd tests && npm install && npm test`).

## Tested with

iPhone with iOS 26 (Safari and Chrome): camera and HEIC library photos, Retake, custom names - stored as 3024x4032 JPEG, about 2.3-2.6 MB, no EXIF

## License

GPL-2.0, the same as RackTables. See [LICENSE](LICENSE).
