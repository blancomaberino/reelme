import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Image, type ImageStyle, type StyleProp, StyleSheet, View, type ViewStyle } from 'react-native';

import { isHttpUrl } from '@/lib/linking';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  // Callers pass box geometry (width/height/borderRadius) valid for both the
  // Image and the fallback View.
  uri: string | null;
  style?: StyleProp<ImageStyle>;
  testID?: string;
};

/**
 * An image that degrades to a placeholder glyph when the source is missing or
 * fails to load. Source thumbnails are presigned R2 URLs that can expire out
 * from under a cached response (T-033 gotcha), so tolerance is required.
 */
export function Thumbnail({ uri, style, testID }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [failed, setFailed] = useState(false);

  // Only http(s) thumbnails are loaded — never a file:/other scheme from the API.
  if (!isHttpUrl(uri) || failed) {
    return (
      <View testID={testID} style={[styles.fallback, style as StyleProp<ViewStyle>]}>
        <Ionicons name="image-outline" size={22} color={c.placeholder} />
      </View>
    );
  }

  return (
    <Image
      testID={testID}
      source={{ uri }}
      style={[styles.image, style]}
      onError={() => setFailed(true)}
      resizeMode="cover"
    />
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    image: { backgroundColor: c.surface },
    fallback: { alignItems: 'center', justifyContent: 'center', backgroundColor: c.primarySoft },
  });
