import { fireEvent, render, screen } from '@testing-library/react-native';
import { Text } from 'react-native';

import { ScreenHeader } from '@/components/screen-header';
import { type } from '@/theme/tokens';

import { mockRouter } from '../../../jest.setup';

/**
 * The consolidated back header (T-104). Fifteen screens hand-rolled this and
 * had drifted apart; the behaviour worth pinning is the part that was NOT
 * uniform before — which back action fires, and whether the title can push the
 * layout around.
 */

beforeEach(() => {
  mockRouter.back.mockClear();
  mockRouter.replace.mockClear();
  mockRouter.canGoBack.mockReturnValue(true);
});

it('renders the title and a labelled back control', () => {
  render(<ScreenHeader title="Mis listas" />);

  expect(screen.getByText('Mis listas')).toBeTruthy();
  expect(screen.getByLabelText('Go back')).toBeTruthy();
});

it('pops the stack when there is history', () => {
  render(<ScreenHeader title="Ajustes" />);

  fireEvent.press(screen.getByTestId('screen-header-back'));

  expect(mockRouter.back).toHaveBeenCalled();
  expect(mockRouter.replace).not.toHaveBeenCalled();
});

/**
 * The regression this component exists to make impossible. Half these screens
 * are deep-link or push-notification targets opened with no stack to pop, where
 * a bare `router.back()` throws "GO_BACK was not handled by any navigator" and
 * strands the user. Three screens imported `safeBack` by hand and three more
 * inlined the same ternary — so the DEFAULT has to be the safe one.
 */
it('lands on the map instead of throwing when there is no stack to pop', () => {
  mockRouter.canGoBack.mockReturnValue(false);
  render(<ScreenHeader title="Estado" />);

  fireEvent.press(screen.getByTestId('screen-header-back'));

  expect(mockRouter.back).not.toHaveBeenCalled();
  expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map');
});

it('honours an explicit onBack over the default', () => {
  const onBack = jest.fn();
  render(<ScreenHeader title="Revisar" onBack={onBack} />);

  fireEvent.press(screen.getByTestId('screen-header-back'));

  expect(onBack).toHaveBeenCalled();
  expect(mockRouter.back).not.toHaveBeenCalled();
});

it('can omit the back control for a root screen', () => {
  render(<ScreenHeader title="Mapa" back={false} />);

  expect(screen.queryByTestId('screen-header-back')).toBeNull();
  expect(screen.getByText('Mapa')).toBeTruthy();
});

it('renders a trailing action when given one', () => {
  render(<ScreenHeader title="Lista" right={<Text>Editar</Text>} />);

  expect(screen.getByText('Editar')).toBeTruthy();
});

it('truncates the title to one line rather than reflowing the screen', () => {
  // Titles are place names and usernames — arbitrarily long. A header that
  // grows to two lines shifts everything below it.
  render(<ScreenHeader title={'Un nombre de lugar absurdamente largo '.repeat(4)} />);

  expect(screen.getByRole('header').props.numberOfLines).toBe(1);
});

it('takes its title size from the type scale, not a hand-typed number', () => {
  // The drift this replaced: 20pt on five screens, 22pt on the rest.
  render(<ScreenHeader title="Perfil" />);

  const style = screen.getByRole('header').props.style;
  const flat = Array.isArray(style) ? Object.assign({}, ...style.flat()) : style;
  expect(flat.fontSize).toBe(type.title.fontSize);
});
