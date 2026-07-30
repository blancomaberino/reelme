# Reelmap design tokens — art direction "MERCADO"

Canonical token list, lifted from the accepted prototype on claude.ai/design
(project `0babf33c-0c30-4af6-8004-049dce002480`, file `Reelmap.dc.html`).
Consumed by the web demo (`apps/api/public/demo.html`) and the mobile theme
(`apps/mobile/src/theme/colors.ts`). Keep these in lockstep with both.

**Logic:** warm Lisbon-market paper (off-white → roasted ink), a terracotta
primary that reads as "food & warmth" (not the old alarm-red), an azulejo teal
for links/secondary/tags, and a market-gold for price/reviews. Surfaces stay
calm and low-chroma so food photography leads; the accents point.

> **Accessibility floor (T-101, 2026-07-30).** Every accent below carries text
> somewhere, so each one clears **WCAG AA 4.5:1** against the surfaces it lands
> on, in both schemes. The values were reached by moving *lightness only* —
> measured in OKLCH, hue drifts ≤3.5° and chroma never increases — so the art
> direction is intact (terracotta still h≈39°, far from the rejected alarm-red
> at h≈22°). The paper itself (`--bg`/`--surface`/`--surface-2`/`--ink`/`--ink-2`)
> was not touched.
>
> `apps/mobile/src/theme/__tests__/colors.test.ts` pins every documented pair and
> fails the build on regression. **A future design pass may re-tune these, but not
> back below AA** — if a value has to move, move it and keep the test green.
**Type:** Georgia serif for names & headings, system-ui sans for the interface,
mono for placeholder/section labels.

## Color — light (primary)

| token | hex | use |
|---|---|---|
| `--bg` | `#F6F0E6` | app canvas |
| `--bg-map` | `#ECE4D6` | warm-tinted Carto base |
| `--surface` | `#FFFFFF` | cards, inputs |
| `--surface-2` | `#F4EDE1` | filled inputs, rating cards |
| `--ink` | `#241E17` | primary text (AA on surface) |
| `--ink-2` | `#5E5347` | secondary text |
| `--muted` | `#6F6353` | captions / tertiary |
| `--line` | `#E6DBC8` | hairline |
| `--line-2` | `#D8CBB4` | stronger divider / input border |
| `--primary` | `#B54A25` | terracotta — buttons, links, pins |
| `--primary-ink` | `#FFFFFF` | text on primary |
| `--primary-soft` | `#FDEDE2` | soft accent fill |
| `--secondary` | `#356E86` | azulejo — links, tag chips |
| `--secondary-soft` | `#DFEBEE` | tag chip fill |
| `--gold` | `#8B5D00` | price / ratings |
| `--gold-soft` | `#F7EEDA` | rating chip fill |
| `--green` | `#377245` | published / open |
| `--green-soft` | `#E2EFE3` | — |
| `--danger` | `#B2391F` | failed / error |
| `--danger-soft` | `#F9E3DB` | — |

## Color — dark

`--bg` `#151109` · `--bg-map` `#1C1710` · `--surface` `#241D14` · `--surface-2`
`#2C2418` · `--ink` `#F3EADA` · `--ink-2` `#C6B9A5` · `--muted` `#A2957F` ·
`--line` `#332A1C` · `--line-2` `#41361F` · `--primary` `#E07A50` ·
`--primary-ink` `#1A1206` · `--primary-soft` `#3A2517` · `--secondary` `#6FA6BE`
· `--secondary-soft` `#1E2E36` · `--gold` `#D2A24A` · `--gold-soft` `#33290F` ·
`--green` `#6FB27C` · `--green-soft` `#1E2C1F` · `--danger` `#E06A50` ·
`--danger-soft` `#3A1C14`

## Radius / shadow / space / type

- radius: `sm 8` `md 12` `lg 16` `xl 22` `pill 999`
- shadow: `sh-1` 1+2px soft · `sh-2` 8/20px · `sh-3` 22/54px (opacities heavier in dark)
- space: `4 8 12 16 20 24 32 48`
- fonts: display = Georgia serif · ui = system-ui · mono = ui-monospace
- type scale: `3xl 34` `2xl 26` `xl 21` `lg 18` `md 15` `sm 13` `xs 11`

## Signature components

- **Pin**: terracotta teardrop (`border-radius:50% 50% 50% 4px` rotated 45°),
  white 2.5px ring, price glyph (€/€€/€€€) counter-rotated; selected → gold + larger.
- **Cluster**: terracotta circle + count, white ring, pulsing halo.
- **Feed card**: thumbnail + serif name + `@creator via @sharer` + gold ★ chip + cuisine·price.
- **Place drawer**: hero, two rating cards (Reelmap / Google), address, azulejo
  tag & dish chips, 9:16 source-video cards, review composer, sharer footer.
- **Share pipeline**: Queued → Analyzing → Review → Published (+ failure), each
  a dot (done ✓ green / active ◐ gold / wait ○ / fail ✕ danger) on a rail.
