# WordPress.org listing assets

Everything in this directory is published to the **`assets/`** directory of the
plugin's SVN repository — a *sibling* of `trunk/`, not a directory inside it.
The release workflow does that via `ASSETS_DIR: .wordpress-org`.

Files placed under `trunk/assets/` instead ship to every user and still leave
the listing page blank. That is why this directory is excluded from the build
in `.distignore`.

## What belongs here

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Plugin icon, search results and the plugin card |
| `icon-256x256.png` | 256×256 | Retina icon |
| `icon.svg` | — | Optional; takes precedence over the PNGs where supported |
| `banner-772x250.png` | 772×250 | Header on the plugin page |
| `banner-1544x500.png` | 1544×500 | Retina header |
| `screenshot-1.png` … | any | Numbered screenshots |

## Screenshot numbering

`screenshot-N.png` binds to the **Nth line** of the `== Screenshots ==` section
in `readme.txt`. There is no filename-to-caption matching — it is purely
positional, so a gap in the numbering or an extra caption fails silently:
captions attach to the wrong image, or vanish.

`bin/check-screenshots.sh` verifies the two stay in step.

## Generating screenshots

`bin/capture-screenshots.js` drives a real browser against a local install:

```sh
WP_PASS='<admin password>' node bin/capture-screenshots.js screenshots-draft
```

Review what lands in `screenshots-draft/`, then copy the keepers into this
directory with their final numbers.
