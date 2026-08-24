# Listing artwork sources

`icon.svg` and `banner.svg` are the sources. The PNGs in `../.wordpress-org/`
are rendered from them — edit here, then re-render:

```sh
rsvg-convert -w 256  -h 256 icon.svg   -o ../.wordpress-org/icon-256x256.png
rsvg-convert -w 128  -h 128 icon.svg   -o ../.wordpress-org/icon-128x128.png
rsvg-convert -w 772  -h 250 banner.svg -o ../.wordpress-org/banner-772x250.png
rsvg-convert -w 1544 -h 500 banner.svg -o ../.wordpress-org/banner-1544x500.png
cp icon.svg ../.wordpress-org/icon.svg
```

Neither this directory nor `.wordpress-org/` ships to users — both are excluded
in `.distignore`, and the build's leak guard fails if either reaches the zip.
`.wordpress-org/` is synced to SVN `assets/`, which is a *sibling* of `trunk/`.

## The ShelterKit system

Shared with ShelterKit Pets, so the family reads as one set:

| Element | Value |
| --- | --- |
| Icon background | vertical `#17A398` → `#0B6E67`, `rx="56"` on 256 |
| Banner background | diagonal `#17A398` → `#0E7E76` → `#095B55` |
| Glyph | solid white, one shape |
| Badge | `#0B6E67` ring, `#FBBF3C` → `#EE9613` fill, white symbol, at (192,192) |
| Tagline | `#BFF0EA`, 19px |
| Pills | `#BFF0EA` at 95%, `#0B5F59` text, 30px tall, `rx="15"` |

Each plugin keeps the shell and changes two things: the **glyph** (Pets a paw,
Donations a heart) and the **badge symbol**, which stands for what that plugin
distinctly does — Pets syncs, so two arrows; Donations takes contributions, so
a plus.

## Two things learned drawing these

**Design for 40px, not 256.** That is the size WordPress.org uses in search
rows, and it is unforgiving. A hand cupping a heart was tried and collapsed
into an unreadable blob; an up-arrow in the badge started reading as a
lowercase "t". Render at 40px and look before committing to a mark.

**The glyph is centred on x=112, not x=128.** Centred, the badge crops the
heart's point and it reads as a lopsided blob rather than a heart. The badge
needs genuinely clear space, which is also why the Pets paw is shifted up and
left.

The title is 50px here against Pets' 58px — "ShelterKit Donations" is five
characters longer and overruns the canvas at 58. Baselines and the pill row
still match Pets exactly, so the two banners line up when stacked.
