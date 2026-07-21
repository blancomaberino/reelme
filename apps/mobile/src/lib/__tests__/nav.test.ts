import { safeBack } from '../nav';

import { mockRouter } from '../../../jest.setup';

beforeEach(() => {
  mockRouter.back.mockClear();
  mockRouter.replace.mockClear();
});

it('pops the stack when there is history', () => {
  mockRouter.canGoBack.mockReturnValueOnce(true);
  safeBack();
  expect(mockRouter.back).toHaveBeenCalledTimes(1);
  expect(mockRouter.replace).not.toHaveBeenCalled();
});

it('falls back to the map when opened fresh (no history)', () => {
  mockRouter.canGoBack.mockReturnValueOnce(false);
  safeBack();
  expect(mockRouter.back).not.toHaveBeenCalled();
  expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map');
});
