import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { Dish, PlaceSourceItem } from '@/api/places';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { platformIcon } from '@/lib/format';
import { openWebUrl } from '@/lib/linking';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  visible: boolean;
  onClose: () => void;
  dishes: Dish[];
  updatedAt: string | null;
  /** The place's sources — the menu was extracted from these posts. */
  sources: PlaceSourceItem[];
};

/**
 * Full menu view (dishes + prices), when the list was last refreshed, and a
 * reference to the source post(s) the data was extracted from — tappable to the
 * original reel.
 */
export function MenuSheet({ visible, onClose, dishes, updatedAt, sources }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);

  // The extraction source(s): prefer the primary, then the rest.
  const ordered = useMemo(
    () => [...sources].sort((a, b) => Number(b.is_primary) - Number(a.is_primary)),
    [sources],
  );

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      <SafeAreaView style={styles.sheet} edges={['bottom']}>
        <View style={styles.handle} />
        <View style={styles.header}>
          <Text style={styles.title}>{t('menu.title')}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t('save.done')} onPress={onClose} hitSlop={8}>
            <Ionicons name="close" size={24} color={c.text} />
          </Pressable>
        </View>

        <ScrollView contentContainerStyle={styles.scroll}>
          {dishes.map((d) => (
            <View key={d.name} style={styles.row}>
              <Text style={styles.dish} numberOfLines={2}>
                {d.name}
                {d.shown_in_video ? ' 🎬' : ''}
              </Text>
              {d.price ? <Text style={styles.price}>{d.price}</Text> : null}
            </View>
          ))}

          {updatedAt ? (
            <Text style={styles.updated}>{t('place.dishesUpdated', { date: fmt.date(updatedAt) })}</Text>
          ) : null}

          {ordered.length > 0 ? (
            <View style={styles.sourceBlock}>
              <Text style={styles.sourceLabel}>{t('menu.extractedFrom')}</Text>
              {ordered.map((s) => {
                const handle = s.influencer?.handle;
                const label = handle ? `@${handle}` : s.source_post.platform;
                return (
                  <Pressable
                    key={s.id}
                    accessibilityRole="link"
                    accessibilityLabel={t('source.openOriginal', { platform: s.source_post.platform })}
                    onPress={() => openWebUrl(s.source_post.url)}
                    disabled={!s.source_post.url}
                    style={({ pressed }) => [styles.source, pressed && styles.pressed]}
                  >
                    <Ionicons name={platformIcon(s.source_post.platform)} size={16} color={c.muted} />
                    <Text style={styles.sourceText} numberOfLines={1}>
                      {label}
                    </Text>
                    {s.source_post.url ? <Ionicons name="open-outline" size={14} color={c.primary} /> : null}
                  </Pressable>
                );
              })}
            </View>
          ) : null}
        </ScrollView>
      </SafeAreaView>
    </Modal>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: 22,
      borderTopRightRadius: 22,
      paddingHorizontal: 20,
      paddingTop: 8,
      maxHeight: '80%',
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 10 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 },
    title: { fontFamily: fonts.display, fontSize: 21, fontWeight: '700', color: c.text },
    scroll: { paddingBottom: 16 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      paddingVertical: 12,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    dish: { flex: 1, fontSize: 16, color: c.text },
    price: { fontSize: 16, fontWeight: '700', color: c.gold },
    updated: { fontSize: 13, color: c.muted, marginTop: 12 },
    sourceBlock: { marginTop: 18, gap: 8 },
    sourceLabel: { fontSize: 13, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: c.muted },
    source: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      paddingVertical: 8,
      paddingHorizontal: 12,
      backgroundColor: c.surface,
      borderRadius: 12,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pressed: { opacity: 0.6 },
    sourceText: { flex: 1, fontSize: 14, color: c.text, fontWeight: '600' },
  });
