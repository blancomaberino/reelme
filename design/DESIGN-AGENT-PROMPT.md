# Reelmap — design-agent prompt

How to use: paste everything below the divider into a fresh Claude session (claude.ai, or
Claude Code with the `frontend-design` skill) as a single prompt. Review the HTML it returns
in a browser, iterate on taste ("warmer", "less busy", "bigger imagery"), then hand the final
HTML file back to the build agent — T-060 tracks reviewing/committing it, T-061 tracks
implementing it in `apps/api/public/demo.html`.

---

You are designing the web experience for **Reelmap**. Produce a complete, self-contained
HTML design prototype — not a wireframe, a finished-looking product — that a developer will
later use as the visual spec.

## The product

Reelmap turns short-form videos into a map. A user shares a TikTok / Instagram reel /
YouTube link ("best tacos in Lisbon"), AI extracts the place, and it lands as a pin on a
shared map with full attribution: the influencer who made the video, the user who shared it,
and a link to the original post. Around that core: search (places, tags, influencers), a
feed of recent shares, place pages with source videos and ratings (app reviews + Google),
public profiles, and follows. Think **"Google Maps meets Instagram, for food & travel
discovery"**. The audience is people who save food reels and never find them again.

## What exists today (and why it fails)

The current demo is a developer console: a dark 360px left sidebar of identical gray
bordered cards (Search / Feed / Sign in / Share a link) next to a Leaflet map with default
blue markers, emoji as icons (📍 🎥 📣), system font, zero imagery. Everything has equal
visual weight; nothing says "video" or "food"; the map reads as an admin tool. Keep its
information architecture (it's correct) — replace its visual language entirely.

## Design goals

1. **Map-first and immersive.** The map is the hero, full-bleed edge to edge. UI floats
   above it (search bar, chips, cards, drawers) — no permanent boxy sidebar frame.
2. **Media-rich.** This is a video product about food. Cards and detail views lead with
   imagery/video-thumbnail shapes (use CSS gradient/SVG placeholders with a small play
   glyph — no external images). Vertical 9:16 thumbnails where a reel is referenced.
3. **Appetizing, warm, confident.** Craft a palette with personality — not the default
   dark-gray-panels-with-red-accent it has now, and not generic AI-startup purple. Food
   photography sits on it, so keep surfaces calm and let accents do the work. Design
   light mode as primary and include a dark variant.
4. **Distinct typography.** A characterful display face for names/headings paired with a
   clean UI face (system-font fallback stack is fine, but define the scale deliberately:
   sizes, weights, letter-spacing as tokens).
5. **Custom map identity.** Custom pin design (cuisine glyph or price tier on the pin),
   custom cluster bubbles, a styled map frame. Assume the base map tiles can be restyled
   (Carto light/dark) — pick which and tint the UI to harmonize.

## Screens & states to design (all in the one file, as scrollable sections or tabbed views)

1. **Map home — desktop (~1440px).** Full-bleed map; floating top search bar with filter
   chips (cuisine, price €–€€€€, "Following"); a left floating feed rail of recent-share
   cards (thumbnail, place name, @influencer via @sharer, time); a compact signed-in
   avatar menu top-right; zoom controls; 3–4 custom pins + one cluster bubble visible.
2. **Map home — mobile (~390px).** Same content re-composed: search collapses to a pill,
   feed becomes a bottom sheet peeking over the map with a drag handle.
3. **Search open.** Results grouped: Places (name, city, distance), Tags (#petiscos),
   Influencers (@handle, follower count) — with empty ("no matches") and loading states.
4. **Place detail** (right drawer on desktop, full sheet on mobile): hero media area,
   name + cuisine/price/status chips, rating block showing BOTH app rating and Google
   rating clearly attributed, address + hours, the source videos list (each: 9:16 thumb,
   platform badge, @influencer, "watch original" link-out), reviews list + inline
   review-composer (star picker + one input), sharer attribution footer.
5. **Profile** (drawer/sheet): avatar, @username, influencer badge, bio, counters
   (shares / followers), Follow and Following button states, grid or list of their
   published places.
6. **Auth + share flow.** Sign-in as a modal (email/password, register toggle, error
   state) — NOT a permanent sidebar card. "Share a link" as the primary CTA button on
   the map that opens a modal: URL input, optional caption, and a submitted-state
   timeline showing the pipeline (queued → analyzing → review → published) with a
   status pill for each stage incl. a failed state.
7. **System states.** Loading skeletons for feed card + detail drawer; an empty-map
   state ("share your first reel"); an error toast.

## Mock data — use exactly this flavor (Lisbon, realistic, no lorem ipsum)

Places: Cervejaria Ramiro (marisqueira, €€€), Time Out Market (food hall, €€), A
Cevicheria (peruvian, €€€), Pastéis de Belém (pastelaria, €), Taberna da Rua das Flores
(petiscos, €€). Influencers: @lisbonfoodguide (128k), @eatswithmarta (46k), @tugafoodie
(89k). Sharers: @marcelo, @ana_p. Ratings like 4.6 (app, 23) / 4.4 (Google, 1.2k).

## Hard deliverable constraints

- **One self-contained HTML file.** Inline CSS in a single `<style>` block. No external
  requests: no CDNs, no web fonts, no image URLs. Placeholders = CSS gradients + inline
  SVG. Minimal inline JS only for tab/drawer/modal toggling between the showcase views.
- **Tokens first.** Every color, radius, shadow, spacing step, and type size defined as
  CSS custom properties in one commented `:root` block (with the dark-mode overrides in
  a `[data-theme="dark"]` block) — these tokens will be lifted verbatim into the real app.
- **A11y floor:** AA contrast for text, visible focus states, ≥44px touch targets on
  mobile views.
- End the file with a visible **"Design system" section**: color swatches with token
  names, the type scale, buttons (primary/ghost/disabled), chips, pills (published /
  review / failed), inputs, the pin + cluster designs at 2× size.

Before building, decide on one strong art direction and commit to it fully — state it in
an HTML comment at the top of the file (name, palette logic, type pairing, mood in two
sentences). Prioritize the map home (desktop + mobile) and place detail; they carry the
product.
