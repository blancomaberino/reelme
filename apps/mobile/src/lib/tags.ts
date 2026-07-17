import type { TagSummary } from '@/api/places';
import type { Locale } from '@/stores/settings';

// Categories / cuisines / vibe tags arrive from AI extraction + Google as free
// English text. This curated dictionary translates them to Spanish; keys are
// lowercased for lookup, anything unlisted falls back to the raw label
// (title-cased) so a tag is never lost — just untranslated.
//
// The vibe_tags + dietary_tags are a CONTROLLED vocabulary (fixed enums in
// extraction.schema.json), so EVERY one of those must have an entry here — this
// is enforced by tags.enum-coverage.test. `cuisines`/`category` are free text,
// so we cover the common ones and title-case the long tail.
const TAGS_ES: Record<string, string> = {
  // meals / times
  breakfast: 'Desayuno',
  brunch: 'Brunch',
  lunch: 'Almuerzo',
  dinner: 'Cena',
  'late-night': 'Trasnoche',
  'late night': 'Trasnoche',
  // cuisines
  modern: 'Moderno',
  contemporary: 'Contemporáneo',
  gourmet: 'Gourmet',
  international: 'Internacional',
  european: 'Europea',
  asian: 'Asiática',
  latin: 'Latina',
  'latin american': 'Latinoamericana',
  regional: 'Regional',
  homestyle: 'Casera',
  'home cooking': 'Casera',
  'comfort food': 'Comida casera',
  rioplatense: 'Rioplatense',
  traditional: 'Tradicional',
  italian: 'Italiana',
  japanese: 'Japonesa',
  chinese: 'China',
  korean: 'Coreana',
  thai: 'Tailandesa',
  indian: 'India',
  mexican: 'Mexicana',
  peruvian: 'Peruana',
  argentine: 'Argentina',
  argentinian: 'Argentina',
  uruguayan: 'Uruguaya',
  brazilian: 'Brasileña',
  spanish: 'Española',
  portuguese: 'Portuguesa',
  french: 'Francesa',
  american: 'Estadounidense',
  mediterranean: 'Mediterránea',
  vietnamese: 'Vietnamita',
  armenian: 'Armenia',
  lebanese: 'Libanesa',
  turkish: 'Turca',
  greek: 'Griega',
  'middle eastern': 'De Medio Oriente',
  arabic: 'Árabe',
  moroccan: 'Marroquí',
  ethiopian: 'Etíope',
  german: 'Alemana',
  british: 'Británica',
  venezuelan: 'Venezolana',
  colombian: 'Colombiana',
  cuban: 'Cubana',
  ecuadorian: 'Ecuatoriana',
  fusion: 'Fusión',
  seafood: 'Mariscos',
  sushi: 'Sushi',
  ramen: 'Ramen',
  pizza: 'Pizza',
  pasta: 'Pasta',
  burger: 'Hamburguesas',
  burgers: 'Hamburguesas',
  steakhouse: 'Parrilla',
  parrilla: 'Parrilla',
  bbq: 'Asado',
  tapas: 'Tapas',
  'street food': 'Comida callejera',
  bakery: 'Panadería',
  cafe: 'Café',
  coffee: 'Café',
  'coffee shop': 'Cafetería',
  dessert: 'Postres',
  desserts: 'Postres',
  'ice cream': 'Heladería',
  bar: 'Bar',
  'wine bar': 'Bar de vinos',
  cocktails: 'Cócteles',
  brewery: 'Cervecería',
  // more food / venue types
  neapolitan: 'Napolitana',
  'neapolitan pizza': 'Pizza napolitana',
  pizzeria: 'Pizzería',
  trattoria: 'Trattoria',
  osteria: 'Osteria',
  bistro: 'Bistró',
  brasserie: 'Brasserie',
  gastropub: 'Gastropub',
  pub: 'Pub',
  diner: 'Diner',
  cantina: 'Cantina',
  taqueria: 'Taquería',
  taquería: 'Taquería',
  creperie: 'Crepería',
  crepes: 'Crepes',
  waffles: 'Waffles',
  pancakes: 'Panqueques',
  gelato: 'Gelato',
  poke: 'Poke',
  smoothies: 'Licuados',
  juice: 'Jugos',
  'juice bar': 'Jugería',
  tea: 'Té',
  'tea house': 'Casa de té',
  izakaya: 'Izakaya',
  'fast food': 'Comida rápida',
  'food truck': 'Food truck',
  'hot dogs': 'Panchos',
  'fried chicken': 'Pollo frito',
  'wood-fired': 'A la leña',
  'wood fired': 'A la leña',
  grill: 'Parrilla',
  chivito: 'Chivito',
  milanesa: 'Milanesa',
  milanesas: 'Milanesas',
  sandwich: 'Sándwiches',
  // dietary / vibe
  vegan: 'Vegano',
  vegetarian: 'Vegetariano',
  'gluten-free': 'Sin gluten',
  'gluten free': 'Sin gluten',
  healthy: 'Saludable',
  'fine dining': 'Alta cocina',
  casual: 'Informal',
  romantic: 'Romántico',
  'family-friendly': 'Familiar',
  'family friendly': 'Familiar',
  'pet-friendly': 'Pet friendly',
  outdoor: 'Al aire libre',
  rooftop: 'Terraza',
  view: 'Con vista',
  cheap: 'Económico',
  'good value': 'Buena relación precio-calidad',
  trendy: 'De moda',
  cozy: 'Acogedor',
  'date night': 'Para una cita',
  'counter seating': 'Barra',
  upscale: 'Elegante',
  'hidden gem': 'Joyita',
  'local favorite': 'Favorito local',
  lively: 'Animado',
  quiet: 'Tranquilo',
  minimalist: 'Minimalista',
  // Controlled vibe/occasion vocabulary (matches the extraction schema enum).
  rustic: 'Rústico',
  spacious: 'Espacioso',
  'outdoor seating': 'Mesas afuera',
  'great view': 'Con vista',
  'good for groups': 'Para grupos',
  'pet friendly': 'Admite mascotas',
  'live music': 'Música en vivo',
  'brunch spot': 'Para brunch',
  'quick eats': 'Comida rápida',
  'group friendly': 'Para grupos',
  takeout: 'Para llevar',
  delivery: 'Delivery',
  'vegan options': 'Opciones veganas',
  'vegetarian options': 'Opciones vegetarianas',
  halal: 'Halal',
  kosher: 'Kosher',
  'dairy-free': 'Sin lácteos',
  'plant-based': 'A base de plantas',
  organic: 'Orgánico',
  'craft beer': 'Cerveza artesanal',
  'natural wine': 'Vino natural',
  tacos: 'Tacos',
  empanadas: 'Empanadas',
  sandwiches: 'Sándwiches',
  salads: 'Ensaladas',
  noodles: 'Fideos',
  dumplings: 'Dumplings',
  shawarma: 'Shawarma',
  falafel: 'Falafel',
  kebab: 'Kebab',
};

/** Whether a tag has an explicit Spanish entry (vs the title-case fallback). */
export function hasSpanishTag(raw: string): boolean {
  return raw.trim().toLowerCase() in TAGS_ES;
}

/**
 * A tag's display label resolved from a catalog by slug: the server-localized
 * `label` when present (ADR-084 #2), else the `localize` fallback applied to the
 * English name (or the humanized slug when the slug isn't in the catalog). Pass
 * `fmt.tag` as `localize`.
 */
export function tagLabelForSlug(tags: TagSummary[], slug: string, localize: (raw: string) => string): string {
  const tag = tags.find((t) => t.slug === slug);
  if (tag?.label) return tag.label;
  return localize(tag?.name ?? slug.replace(/-/g, ' '));
}

/**
 * Normalize text for search: lowercase + strip the Spanish diacritics that
 * appear in tags (Hermes-safe — avoids String.normalize). Makes matching
 * case- and accent-insensitive, so "Café", "cafe" and "CAFÉ" all compare equal.
 */
export function foldSearch(s: string): string {
  return s
    .toLowerCase()
    .trim()
    .replace(/[áàä]/g, 'a')
    .replace(/[éèë]/g, 'e')
    .replace(/[íìï]/g, 'i')
    .replace(/[óòö]/g, 'o')
    .replace(/[úùü]/g, 'u')
    .replace(/ñ/g, 'n')
    .replace(/ç/g, 'c');
}

/**
 * The folded strings a tag is matched against: its display `label` (what the
 * user sees — pass the same string you render) plus its raw name/slug, so search
 * and display never disagree. Precompute once per catalog/locale rather than
 * re-folding on every keystroke.
 */
export function tagHaystacks(label: string, name: string, slug: string): string[] {
  return [foldSearch(label), foldSearch(name), foldSearch(slug)];
}

/**
 * Earliest index at which the folded query occurs in any haystack, or -1 for no
 * match (0 = starts-with, so callers can rank prefix matches ahead of mid-word).
 * An empty query matches everything at 0. The match is case-insensitive,
 * accent-insensitive, and substring ("part of the word").
 */
export function haystackMatchIndex(haystacks: string[], foldedQuery: string): number {
  if (!foldedQuery) return 0;
  let best = -1;
  for (const h of haystacks) {
    const i = h.indexOf(foldedQuery);
    if (i !== -1 && (best === -1 || i < best)) best = i;
  }
  return best;
}

/** Match a single tag against a raw query (folds both sides). See {@link haystackMatchIndex}. */
export function tagMatchIndex(name: string, slug: string, query: string, locale: Locale): number {
  return haystackMatchIndex(tagHaystacks(localizeTag(name, locale), name, slug), foldSearch(query));
}

/** Title-case a raw tag for display when it isn't in the dictionary. */
function titleCase(raw: string): string {
  return raw.replace(/\w\S*/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());
}

/**
 * Localize a category/cuisine/tag label. Spanish maps known tags via the
 * dictionary; everything else (and all English) is title-cased so casing is
 * consistent regardless of how the API cased it.
 */
export function localizeTag(raw: string | null | undefined, locale: Locale): string {
  if (!raw) return '';
  const key = raw.trim().toLowerCase();
  if (locale === 'es' && TAGS_ES[key]) return TAGS_ES[key];
  return titleCase(raw.trim());
}
