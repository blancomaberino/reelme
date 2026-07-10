import { render, screen } from '@testing-library/react-native';

import WelcomeScreen from '../app/(auth)/welcome';

describe('WelcomeScreen', () => {
  it('renders the app title', () => {
    render(<WelcomeScreen />);

    expect(screen.getByText('Reelmap')).toBeTruthy();
  });
});
