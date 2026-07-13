import type { Locale } from '@/stores/settings';

// Categories / cuisines / vibe tags arrive from AI extraction + Google as free
// English text. This curated dictionary translates the common ones to Spanish;
// anything not listed falls back to the raw label (title-cased), so an unknown
// tag is never lost — just untranslated. Keys are lowercased for lookup.
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
};

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
