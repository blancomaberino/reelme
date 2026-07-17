import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { useState } from 'react';

import type { TagSummary } from '@/api/places';
import { TagAutocomplete } from '@/components/filters/tag-autocomplete';
import { useSettingsStore } from '@/stores/settings';

function tag(slug: string, name: string): TagSummary {
  return { id: `t-${slug}`, kind: 'cuisine', name, slug };
}

// The candidate set the caller passes in (facet of my places, or global catalog).
// "casual" is displayed as "Informal" in Spanish; "coffee" as "Café" — the cases
// the English-only server can't match.
const CATALOG = [tag('ramen', 'Ramen'), tag('sushi', 'Sushi'), tag('casual', 'casual'), tag('coffee', 'coffee')];

/** Drives TagAutocomplete with real selection state over a fixed catalog. */
function Harness({ initial = [] as string[], catalog = CATALOG }) {
  const [sel, setSel] = useState<string[]>(initial);
  const toggle = (slug: string) => setSel((s) => (s.includes(slug) ? s.filter((x) => x !== slug) : [...s, slug]));
  return <TagAutocomplete catalog={catalog} selected={sel} onToggle={toggle} />;
}

it('offers the catalog when empty and selects one into a removable chip', () => {
  render(<Harness />);

  fireEvent.press(screen.getByLabelText('Ramen'));

  expect(screen.getByLabelText('Remove Ramen filter')).toBeOnTheScreen();
  expect(screen.queryByLabelText('Ramen')).toBeNull(); // left the suggestions
});

it('matches case-insensitively and on part of the word, filtering out non-matches', () => {
  render(<Harness />);

  // "ASU" (uppercase, mid-word) matches "casual" but not "Ramen".
  fireEvent.changeText(screen.getByLabelText('Search tags…'), 'ASU');
  expect(screen.getByLabelText('Casual')).toBeOnTheScreen();
  expect(screen.queryByLabelText('Ramen')).toBeNull();
});

it('finds a tag by its Spanish label even though it is stored in English', () => {
  useSettingsStore.setState({ locale: 'es' });
  render(<Harness />);

  // "casual" is shown as "Informal" — searching the Spanish label finds it.
  fireEvent.changeText(screen.getByLabelText('Buscar etiquetas…'), 'Informal');
  fireEvent.press(screen.getByLabelText('Informal'));
  expect(screen.getByLabelText('Quitar filtro Informal')).toBeOnTheScreen();
});

it('is accent-insensitive on localized labels', () => {
  useSettingsStore.setState({ locale: 'es' });
  render(<Harness />);

  // "cafe" (no accent) matches the accented label "Café" (for "coffee").
  fireEvent.changeText(screen.getByLabelText('Buscar etiquetas…'), 'cafe');
  expect(screen.getByLabelText('Café')).toBeOnTheScreen();
});

it('shows a no-results message when nothing matches', () => {
  render(<Harness />);

  fireEvent.changeText(screen.getByLabelText('Search tags…'), 'zzzz');
  expect(screen.getByText(/No tags for/)).toBeOnTheScreen();
});

it('removes a pre-selected tag when its chip is tapped', async () => {
  render(<Harness initial={['ramen']} />);

  fireEvent.press(screen.getByLabelText('Remove Ramen filter'));
  await waitFor(() => expect(screen.queryByLabelText('Remove Ramen filter')).toBeNull());
});
