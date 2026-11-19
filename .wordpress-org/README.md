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

Take them only on a demo site filled with made-up data, **never on a site
with real donors**. The August 2026 set was taken on a shelter's development
copy and published its real memorials and members.

1. Install WordPress, WooCommerce and this plugin on a throwaway site.
2. Fill it: `wp eval-file bin/seed-demo-data.php --user=admin`. It refuses a
   site that already has donors or orders, makes up every person, business
   and pet, uses example addresses, and sets the time zone to Los Angeles.
   Set `SKDON_DEMO_FONT` to a TrueType font for nicer business logos.
3. Optionally, put a real gift through: a memorial with the family told,
   bought at checkout and completed by an admin, then edited. Screenshot 8,
   a record's history, shows that sequence.
4. Capture:

   ```sh
   PLAYWRIGHT_PATH=<a node_modules/playwright> WP_URL=<demo site> \
   WP_PASS='<admin password>' node bin/capture-screenshots.js screenshots-draft
   ```

   It has no default site, and refuses unless every donor has an example
   address. It writes `screenshot-1.png` to `screenshot-10.png` in caption
   order.

Review `screenshots-draft/`, copy the keepers into this directory, and run
`bin/check-screenshots.sh`.
