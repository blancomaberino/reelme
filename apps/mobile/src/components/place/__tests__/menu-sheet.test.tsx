import { render, screen } from '@testing-library/react-native';

import type { Dish } from '@/api/places';
import { MenuSheet } from '@/components/place/menu-sheet';
import { useSettingsStore } from '@/stores/settings';

const DISHES: Dish[] = [
  { name: 'shawarma', shown_in_video: true, price: '$460' },
  { name: 'falafel', shown_in_video: false, price: null },
];

beforeEach(() => useSettingsStore.setState({ locale: 'en' }));

it('labels the menu source language and shows the video legend', () => {
  render(<MenuSheet visible onClose={() => {}} dishes={DISHES} updatedAt={null} language="es" sources={[]} />);

  expect(screen.getByText('Menu in Spanish')).toBeOnTheScreen();
  expect(screen.getByText('🎬 shown in the video')).toBeOnTheScreen();
  // Dish names are shown verbatim in the source language.
  expect(screen.getByText(/shawarma/)).toBeOnTheScreen();
});

it('omits the language label when the language is unknown/absent', () => {
  render(<MenuSheet visible onClose={() => {}} dishes={DISHES} updatedAt={null} language={null} sources={[]} />);
  expect(screen.queryByText(/Menu in/)).toBeNull();
});

it('omits the video legend when no dish is shown in the video', () => {
  const noVideo: Dish[] = [{ name: 'falafel', shown_in_video: false, price: null }];
  render(<MenuSheet visible onClose={() => {}} dishes={noVideo} updatedAt={null} language="en" sources={[]} />);
  expect(screen.queryByText(/shown in the video/)).toBeNull();
});
