import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';
import { space, type } from '@/theme/tokens';

export type Counters = {
  followers: number;
  following: number;
  published_shares: number;
};

/**
 * Followers · Following · Shares, as one row (T-039).
 *
 * Extracted from the public profile rather than re-typed onto the own-profile
 * tab. Two copies of a counter row is how you end up with one that navigates and
 * one that doesn't, or one that reads `published_shares` and one that reads a
 * differently-named field — and the numbers here are the same numbers, from the
 * same endpoint, for both screens.
 *
 * Followers and following are pressable and open the existing list screens;
 * shares is not, because the list of a person's shares is their places grid,
 * which is already the body of the screen underneath.
 */
export function ProfileCounters({ username, counters }: { username: string; counters: Counters }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.counters}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${counters.followers} ${t('profileUser.followers')}`}
        onPress={() => router.push({ pathname: '/users/[username]/followers', params: { username } })}
        style={styles.counter}
        testID="profile-counter-followers"
      >
        <Text style={styles.counterValue}>{counters.followers}</Text>
        <Text style={styles.counterLabel}>{t('profileUser.followers')}</Text>
      </Pressable>

      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${counters.following} ${t('profileUser.following')}`}
        onPress={() => router.push({ pathname: '/users/[username]/following', params: { username } })}
        style={styles.counter}
        testID="profile-counter-following"
      >
        <Text style={styles.counterValue}>{counters.following}</Text>
        <Text style={styles.counterLabel}>{t('profileUser.following')}</Text>
      </Pressable>

      <View style={styles.counter} testID="profile-counter-shares">
        <Text style={styles.counterValue}>{counters.published_shares}</Text>
        <Text style={styles.counterLabel}>{t('profileUser.shares')}</Text>
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    counters: { flexDirection: 'row', justifyContent: 'space-around', paddingVertical: space.xs },
    counter: { alignItems: 'center', gap: space.xxs },
    // Snapped to the ramp on extraction: this was a raw 18, which is one of the
    // stray sizes tokens.ts exists to retire (the ramp goes 16 → 20). Bold 16
    // still reads as the emphasised number against its 12pt label.
    counterValue: { ...type.bodyLg, fontWeight: '700', color: c.text },
    counterLabel: { ...type.caption, color: c.muted },
  });
