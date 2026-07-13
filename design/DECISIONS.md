# Reelmap design decisions

## Accepted direction — "MERCADO" (2026-07-13)

The user ran the `DESIGN-AGENT-PROMPT.md` brief in claude.ai/design and accepted
the **MERCADO** art direction ("this is the design you should work on").

- **Source of truth:** claude.ai/design project `0babf33c-0c30-4af6-8004-049dce002480`,
  file `Reelmap.dc.html` (read via the `claude_design` MCP / DesignSync tool).
- **Token reference:** [`tokens.md`](tokens.md) — mirrored into
  `apps/api/public/demo.html` (`:root` block) and `apps/mobile/src/theme/colors.ts`.

### What it is
Warm Lisbon-market paper canvas; **terracotta** primary (food & warmth, not the
old alarm-red `#ff5a5f`); **azulejo** teal for links / tags; **market-gold** for
price and ratings; calm low-chroma surfaces so food photography leads. Georgia
serif for place names & headings, system sans for the interface, mono for
section/placeholder labels. Custom teardrop pins + pulsing clusters; floating
map chrome (search + chips), feed as a rail/bottom-sheet, auth/share in modals,
place detail & profile as a drawer. Light-primary with a full dark variant.

### Rejected (don't resurrect)
- The old dark dev-console look: gray bordered sidebar cards, default blue
  Leaflet markers, emoji icons, alarm-red accent, no imagery, no hierarchy.
- "AI-startup default" (purple gradients, glassmorphism) — the brief demanded a
  stated art direction; MERCADO is that direction.
- Dark-only designs — food/day map use wants a light primary.

## Implementation
- **T-061** (web): `apps/api/public/demo.html` rebuilt to MERCADO (PR #40, `562cc06`).
  Single static file, Leaflet + Carto only, all prior behavior + XSS guards preserved.
- **Mobile**: MERCADO ported into the Expo theme + discovery screens (PR #41,
  `bffa72e`) — terracotta teardrop pins, serif names, gold rating chips, azulejo
  chips, terracotta tab tint.
