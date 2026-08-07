import { matchesConfirmationWord } from '../confirmation-word';

/**
 * The typed-confirmation gate on account deletion (T-050).
 *
 * A note on what is NOT tested here, because it matters more than what is: the
 * real hazard is `toLocaleUpperCase()`, which uses the DEVICE locale — on a
 * Turkish device `'eliminar'` becomes `'ELİMİNAR'` and the gate becomes
 * impossible to satisfy. **Jest cannot reproduce that.** `toLocaleUpperCase()`
 * with no argument follows the Node process's ICU default, so in this
 * environment it is indistinguishable from `toUpperCase()` and a test asserting
 * "Turkish is fine" would pass against the broken implementation too. I wrote
 * that test first, confirmed it passed with the bug present, and deleted it.
 *
 * So the defence is structural rather than asserted: the implementation folds
 * A–Z by code point, which cannot emit a non-ASCII character in any locale. The
 * cases below pin that fold's behaviour.
 */
it('accepts the word regardless of case or stray whitespace', () => {
  expect(matchesConfirmationWord('DELETE', 'DELETE')).toBe(true);
  expect(matchesConfirmationWord('delete', 'DELETE')).toBe(true);
  expect(matchesConfirmationWord('  Delete ', 'DELETE')).toBe(true);
  // The Spanish sentinel — the one that carries the `i` at risk.
  expect(matchesConfirmationWord('eliminar', 'ELIMINAR')).toBe(true);
  expect(matchesConfirmationWord(' Eliminar ', 'ELIMINAR')).toBe(true);
});

it('rejects anything that is not the word', () => {
  expect(matchesConfirmationWord('', 'DELETE')).toBe(false);
  expect(matchesConfirmationWord('DEL', 'DELETE')).toBe(false);
  expect(matchesConfirmationWord('DELETE ACCOUNT', 'DELETE')).toBe(false);
  expect(matchesConfirmationWord('ELIMIN', 'ELIMINAR')).toBe(false);
});

it('never folds an ASCII letter into a non-ASCII one', () => {
  // The property the Turkish case actually needs, stated directly: whatever
  // the device locale, folding must stay inside ASCII. `'i'` is the character
  // Turkish casing moves (to `'İ'`, U+0130), so a fold that ever produced one
  // would fail here — and this assertion does not depend on the ambient locale
  // to do its job.
  expect(matchesConfirmationWord('i', 'I')).toBe(true);
  expect(matchesConfirmationWord('İ', 'I')).toBe(false);
  expect(matchesConfirmationWord('eliminar', 'ELİMİNAR')).toBe(false);
});
