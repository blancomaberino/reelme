import { StyleSheet, Text, View } from 'react-native';

import { priceGlyphs } from '@/lib/format';

type Props = {
  category: string | null;
  priceRange: number | null;
  selected: boolean;
};

// MERCADO pin colors — kept literal (markers render outside the themed tree and
// must read on both light and dark map tiles). Terracotta teardrop, white ring.
const PIN = '#CF5C34';
const PIN_SELECTED = '#B4842A'; // market-gold when selected

/**
 * The map marker visual — a terracotta teardrop (rotated rounded square) with
 * the price tier, per the MERCADO art direction. Deliberately cheap: no images,
 * no theme hook (markers must not subscribe to re-renders).
 */
export function PinGlyph({ priceRange, selected }: Props) {
  const price = priceGlyphs(priceRange) || '•';
  const size = selected ? 40 : 34;
  const color = selected ? PIN_SELECTED : PIN;
  return (
    <View style={[styles.teardrop, { width: size, height: size, backgroundColor: color }]}>
      <Text style={styles.label}>{price}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  teardrop: {
    // Rounded on three corners, sharp bottom-left → a teardrop once rotated 45°.
    borderTopLeftRadius: 999,
    borderTopRightRadius: 999,
    borderBottomRightRadius: 999,
    borderBottomLeftRadius: 4,
    transform: [{ rotate: '45deg' }],
    borderWidth: 2.5,
    borderColor: '#FFFFFF',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.2,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 3 },
    elevation: 4,
  },
  // Counter-rotate the glyph so the text reads upright inside the rotated pin.
  label: { transform: [{ rotate: '-45deg' }], color: '#FFFFFF', fontSize: 11, fontWeight: '800', letterSpacing: -0.5 },
});
