import { fireEvent, render, screen } from '@testing-library/react-native';

import { Button } from '@/components/button';
import { schemes } from '@/theme/colors';
import { radius, space } from '@/theme/tokens';

/**
 * Button gained danger / ghost / link variants, a size prop and an icon slot in
 * T-104. Before that it had two variants and one size, so only 13 of 29 screens
 * imported it — the rest hand-rolled a Pressable and each re-invented the
 * padding, the pressed state and the disabled opacity.
 */

/** Flatten the style prop, resolving the function form Pressable accepts. */
function styleOf(label: string, state = { pressed: false }) {
  const style = screen.getByLabelText(label).props.style;
  const resolved = typeof style === 'function' ? style(state) : style;
  return Object.assign({}, ...[resolved].flat(Infinity).filter(Boolean));
}

it('fires onPress and reports the button role', () => {
  const onPress = jest.fn();
  render(<Button title="Guardar" onPress={onPress} />);

  fireEvent.press(screen.getByText('Guardar'));

  expect(onPress).toHaveBeenCalled();
  expect(screen.getByRole('button')).toBeTruthy();
});

it('swallows presses while loading and says so to a screen reader', () => {
  const onPress = jest.fn();
  render(<Button title="Publicar" loading onPress={onPress} accessibilityLabel="Publicar" />);

  fireEvent.press(screen.getByLabelText('Publicar'));

  expect(onPress).not.toHaveBeenCalled();
  expect(screen.getByLabelText('Publicar').props.accessibilityState).toMatchObject({
    busy: true,
    disabled: true,
  });
  // The label is replaced by a spinner, so asserting on it would be asserting
  // on nothing.
  expect(screen.queryByText('Publicar')).toBeNull();
});

it('swallows presses while disabled', () => {
  const onPress = jest.fn();
  render(<Button title="Enviar" disabled onPress={onPress} accessibilityLabel="Enviar" />);

  fireEvent.press(screen.getByLabelText('Enviar'));

  expect(onPress).not.toHaveBeenCalled();
});

describe('variants', () => {
  it.each([
    ['primary', schemes.light.onPrimary],
    ['secondary', schemes.light.primary],
    ['danger', schemes.light.onPrimary],
    ['ghost', schemes.light.text],
    ['link', schemes.light.primary],
  ] as const)('tints the %s label from the palette', (variant, expected) => {
    render(<Button title="X" variant={variant} accessibilityLabel="X" />);

    expect(screen.getByText('X').props.style.flat().at(-1)).toMatchObject({ color: expected });
  });

  it('fills danger with the danger colour', () => {
    render(<Button title="Borrar" variant="danger" accessibilityLabel="Borrar" />);

    expect(styleOf('Borrar').backgroundColor).toBe(schemes.light.danger);
  });

  it('gives ghost no fill and no border, so it cannot compete with the CTA', () => {
    render(<Button title="Quizás" variant="ghost" accessibilityLabel="Quizás" />);

    const style = styleOf('Quizás');
    expect(style.backgroundColor).toBe('transparent');
    expect(style.borderWidth).toBeUndefined();
  });

  it('renders link as underlined text with no button padding', () => {
    // A link sits inline in a sentence; padding would push the sentence around.
    render(<Button title="Ver más" variant="link" accessibilityLabel="Ver más" />);

    expect(styleOf('Ver más').paddingVertical).toBeUndefined();
    expect(screen.getByText('Ver más').props.style.flat()).toContainEqual(
      expect.objectContaining({ textDecorationLine: 'underline' }),
    );
  });
});

describe('sizing and icons', () => {
  it('takes its padding and radius from the scale', () => {
    render(<Button title="A" accessibilityLabel="A" />);

    const style = styleOf('A');
    expect(style.paddingVertical).toBe(space.md);
    expect(style.paddingHorizontal).toBe(space.xl);
    expect(style.borderRadius).toBe(radius.lg);
  });

  it('shrinks to the sm step for a row-inline action', () => {
    render(<Button title="B" size="sm" accessibilityLabel="B" />);

    const style = styleOf('B');
    expect(style.paddingVertical).toBe(space.xs);
    expect(style.paddingHorizontal).toBe(space.md);
  });

  it('renders a leading icon tinted to match the label', () => {
    render(<Button title="Compartir" icon="share-outline" accessibilityLabel="Compartir" />);

    // The icon is a sibling of the label inside the content row; its presence
    // is what the slot promises.
    expect(screen.getByText('Compartir')).toBeTruthy();
    expect(screen.getByLabelText('Compartir')).toBeTruthy();
  });
});
