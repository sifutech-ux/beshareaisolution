# BeShare Aurora V2 web artwork

These assets preserve the approved Aurora V2 vector paths, gradients and outlined
letterforms. The full horizontal logos use a trimmed SVG viewBox with a 2-unit
margin around the original visible artwork, so website headers do not inherit
the production file's unused canvas. No logo geometry has been redrawn.

## Files

Each product has the following files, prefixed with its exact slug:

- `beshare-ai-solution`
- `beshare-business-copilot`
- `beshare-ai-os`
- `beshare-ai-marketing`

Suffixes:

- `-logo-dark.svg`: white wordmark for dark surfaces.
- `-logo-light.svg`: dark wordmark for light surfaces.
- `-icon.svg`: complete symbol, with its original 256 × 256 canvas.
- `-favicon.svg`: simplified micro icon on navy, copied from the approved kit.
- `-apple-touch-icon.png`: 180 × 180 export of the approved navy avatar.

The root `/favicon.svg` is the parent AI Solution favicon. Product pages link to
their own favicon in this folder. Do not replace third-party integration icons
with BeShare marks; those identify external services.

## Shared HTML/CSS contract

Load `/assets/brand.css?v=20261127-aurora-v2` after the page styles. A brand link
uses `.bs-brand` and an accessible name in `aria-label`. Paired decorative logo
images use `alt=""`, `aria-hidden="true"`, `.bs-brand__logo` and one of
`.bs-brand__logo--dark` / `.bs-brand__logo--light`.

Set `--bs-brand-width` on the wrapper when a layout needs a different width.
Use `.bs-brand__icon` and `--bs-brand-icon-size` for symbols. The theme styles
support `data-tema` and `data-theme` values `cerah`, `gelap` and `sistem`.
Set `data-brand-surface="dark"` or `"light"` on the brand wrapper for a fixed
surface that should override the document theme.

The web rollout does not state that any trademark is registered or cleared.
