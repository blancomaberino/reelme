import { fireEvent, render, screen } from '@testing-library/react-native';
import { Text } from 'react-native';

import { SheetShell } from '@/components/sheet-shell';

/**
 * The shared bottom-sheet chrome (T-110). What is worth testing here is the way
 * OUT, because the two consumers leave by different routes: the filter sheet
 * commits with its footer button, and the country picker has no footer at all —
 * which for a while meant its only dismissal was an unlabelled backdrop, i.e.
 * no dismissal at all for a screen-reader user.
 */
function open(props: Partial<React.ComponentProps<typeof SheetShell>> = {}) {
  const onClose = jest.fn();
  render(
    <SheetShell visible onClose={onClose} title="País" {...props}>
      <Text>body</Text>
    </SheetShell>,
  );
  return onClose;
}

it('offers a labelled close control when there is no footer', () => {
  const onClose = open();

  fireEvent.press(screen.getByTestId('sheet-close'));
  expect(onClose).toHaveBeenCalled();
});

it('names that control for a screen reader', () => {
  open();

  // Two controls carry it — the visible ✕ and the backdrop behind the sheet.
  expect(screen.getAllByLabelText('Close').length).toBeGreaterThanOrEqual(2);
});

it('dismisses from the backdrop, which is a control and has to say so', () => {
  const onClose = open();

  // The backdrop is the last "Close" in the tree order the header sits ahead of.
  const backdrops = screen.getAllByLabelText('Close');
  fireEvent.press(backdrops[backdrops.length - 1]);
  expect(onClose).toHaveBeenCalled();
});

it('drops the header close when a footer already commits and dismisses', () => {
  // The filter sheet's "Apply" both applies and closes; a second exit beside it
  // would be two ways out with no way to tell them apart.
  open({ footer: <Text>Apply</Text> });

  expect(screen.queryByTestId('sheet-close')).toBeNull();
});

it('still shows the trailing text action alongside the close', () => {
  const onPress = jest.fn();
  open({ action: { label: 'Remove country', onPress } });

  fireEvent.press(screen.getByLabelText('Remove country'));
  expect(onPress).toHaveBeenCalled();
  expect(screen.getByTestId('sheet-close')).toBeTruthy();
});
