import type { ReactNode } from 'react';

import { render } from '@testing-library/react-native';

// Capture the props handed to expo-router primitives so we can assert the
// entry redirect target and the tab wiring without booting a real router.
const mockRouter = {
  redirectHref: null as string | null,
  initialRouteName: null as string | null,
  tabNames: [] as string[],
};

jest.mock('expo-router', () => {
  const React = require('react');
  return {
    Redirect: ({ href }: { href: string }) => {
      mockRouter.redirectHref = href;
      return null;
    },
    Tabs: Object.assign(
      ({ children, initialRouteName }: { children?: ReactNode; initialRouteName?: string }) => {
        mockRouter.initialRouteName = initialRouteName ?? null;
        return React.createElement(React.Fragment, null, children);
      },
      {
        Screen: ({ name }: { name: string }) => {
          mockRouter.tabNames.push(name);
          return null;
        },
      },
    ),
  };
});

import Index from '../app/index';
import MainTabsLayout from '../app/(main)/_layout';

describe('navigation wiring', () => {
  beforeEach(() => {
    mockRouter.redirectHref = null;
    mockRouter.initialRouteName = null;
    mockRouter.tabNames = [];
  });

  it('boots the entry route into the map tab', () => {
    render(<Index />);

    expect(mockRouter.redirectHref).toBe('/(main)/map');
  });

  it('mounts the four tabs in order with map as the initial route', () => {
    render(<MainTabsLayout />);

    expect(mockRouter.tabNames).toEqual(['map', 'feed', 'share', 'profile']);
    expect(mockRouter.initialRouteName).toBe('map');
  });
});
