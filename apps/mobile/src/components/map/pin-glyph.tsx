import { StyleSheet, Text, View } from 'react-native';

import { priceGlyphs } from '@/lib/format';

type Props = {
  category: string | null;
  priceRange: number | null;
  selected: boolean;
};

// Brand pin colors — kept literal (markers render outside the themed tree and
// must read on both light and dark map tiles).
const PIN = '#208AEF';
const PIN_SELECTED = '#E0245E';

/**
 * The map marker visual — a rounded teardrop with the price tier. Deliberately
 * cheap: no images, no theme hook (markers must not subscribe to re-renders).
 */
export function PinGlyph({ priceRange, selected }: Props) {
  const price = priceGlyphs(priceRange);
  return (
    <View style={styles.wrap}>
      <View style={[styles.bubble, { backgroundColor: selected ? PIN_SELECTED : PIN }, selected && styles.bubbleSelected]}>
        <Text style={styles.label}>{price || '•'}</Text>
      </View>
      <View style={[styles.tail, { borderTopColor: selected ? PIN_SELECTED : PIN }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { alignItems: 'center' },
  bubble: {
    minWidth: 28,
    height: 28,
    borderRadius: 14,
    paddingHorizontal: 8,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: '#FFFFFF',
  },
  bubbleSelected: { minWidth: 34, height: 34, borderRadius: 17, transform: [{ scale: 1.05 }] },
  label: { color: '#FFFFFF', fontSize: 12, fontWeight: '700' },
  tail: {
    width: 0,
    height: 0,
    borderLeftWidth: 4,
    borderRightWidth: 4,
    borderTopWidth: 6,
    borderLeftColor: 'transparent',
    borderRightColor: 'transparent',
    marginTop: -1,
  },
});
