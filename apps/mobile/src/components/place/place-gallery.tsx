import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import {
  FlatList,
  type LayoutChangeEvent,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';

import type { PlaceGalleryImage } from '@/api/places';
import { type Palette, useColors } from '@/theme/colors';

import { Thumbnail } from './thumbnail';

type Props = {
  images: PlaceGalleryImage[];
  testID?: string;
};

// Matches the single-hero card (see [slug].tsx `hero`) so a place toggling
// between one photo and many doesn't jump in size.
const GALLERY_HEIGHT = 190;

// The place detail's ScrollView pads its content by 20 on each side; the gallery
// is a child of it, so this is the width available before onLayout measures it
// exactly (avoids a first-frame flash at the wrong width).
const SCROLL_PADDING = 20;

/**
 * A swipeable business-photo gallery (T-099) shown on the place detail when a
 * place has more than one photo — owned website images first, then
 * business-attributed Google photos (see GalleryBuilder). One image paginates
 * per swipe with terracotta page dots; a Google photo's attribution shows as a
 * small photo-credit scrim. Each image degrades to the shared {@link Thumbnail}
 * placeholder if its URL is missing or fails to load (Google photo URLs can
 * rot between enrichments). The caller renders the single hero for length ≤ 1.
 */
export function PlaceGallery({ images, testID }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { width: screenWidth } = useWindowDimensions();
  const [width, setWidth] = useState(Math.max(1, screenWidth - SCROLL_PADDING * 2));
  const [index, setIndex] = useState(0);

  const onLayout = (e: LayoutChangeEvent) => {
    const w = e.nativeEvent.layout.width;
    if (w > 0 && w !== width) {
      setWidth(w);
    }
  };

  const onMomentumEnd = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const next = Math.round(e.nativeEvent.contentOffset.x / width);
    if (next !== index) {
      setIndex(next);
    }
  };

  // Clamp the active dot in case a layout change shifts the rounded offset.
  const active = Math.min(index, images.length - 1);

  return (
    <View testID={testID} style={styles.wrap} onLayout={onLayout}>
      <FlatList
        data={images}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onMomentumScrollEnd={onMomentumEnd}
        keyExtractor={(item, i) => `${item.url}-${i}`}
        renderItem={({ item }) => (
          <View style={[styles.slide, { width }]}>
            <Thumbnail uri={item.url} style={[styles.image, { width }]} />
            {item.attribution ? (
              <View style={styles.credit}>
                <Ionicons name="camera-outline" size={11} color="#FFFFFF" />
                <Text style={styles.creditText} numberOfLines={1}>
                  {item.attribution}
                </Text>
              </View>
            ) : null}
          </View>
        )}
      />

      <View style={styles.dots}>
        {images.map((image, i) => (
          <View key={`${image.url}-${i}`} style={[styles.dot, i === active && styles.dotActive]} />
        ))}
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { marginBottom: 4 },
    slide: { position: 'relative' },
    image: { height: GALLERY_HEIGHT, borderRadius: 16 },
    // Photo-credit scrim for Google-sourced images — a soft dark pill so the
    // uploader attribution reads over any photo without fighting the paper theme.
    credit: {
      position: 'absolute',
      left: 10,
      bottom: 10,
      maxWidth: '70%',
      flexDirection: 'row',
      alignItems: 'center',
      gap: 4,
      paddingHorizontal: 8,
      paddingVertical: 3,
      borderRadius: 999,
      backgroundColor: 'rgba(24, 18, 12, 0.62)',
    },
    creditText: { color: '#FFFFFF', fontSize: 11, fontWeight: '500', flexShrink: 1 },
    dots: {
      flexDirection: 'row',
      justifyContent: 'center',
      alignItems: 'center',
      gap: 6,
      marginTop: 10,
    },
    dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: c.border },
    // Active page: terracotta, slightly elongated so the current photo is obvious.
    dotActive: { width: 18, backgroundColor: c.primary },
  });
