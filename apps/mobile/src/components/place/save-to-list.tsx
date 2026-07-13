import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateList, useListMembership, useLists } from '@/api/hooks/useLists';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  placeId: string;
  visible: boolean;
  onClose: () => void;
};

/**
 * "Save to a list" picker (T-062). Lists the viewer's lists with a checkmark for
 * those already holding this place (via ?contains), toggling membership on tap.
 * A create-new row adds a list and immediately saves the place into it.
 */
export function SaveToListSheet({ placeId, visible, onClose }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: lists, isLoading } = useLists(visible ? placeId : undefined);
  const { add, remove } = useListMembership();
  const create = useCreateList();
  const [newName, setNewName] = useState('');
  const [creating, setCreating] = useState(false);

  const toggle = (listId: string, contains: boolean) => {
    if (contains) remove.mutate({ listId, placeId });
    else add.mutate({ listId, placeId });
  };

  const createAndAdd = () => {
    const name = newName.trim();
    if (!name) return;
    create.mutate(name, {
      onSuccess: (list) => {
        add.mutate({ listId: list.id, placeId });
        setNewName('');
        setCreating(false);
      },
    });
  };

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      <SafeAreaView style={styles.sheet} edges={['bottom']}>
        <View style={styles.handle} />
        <View style={styles.header}>
          <Text style={styles.title}>{t('save.title')}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t('save.done')} onPress={onClose} hitSlop={8}>
            <Text style={styles.done}>{t('save.done')}</Text>
          </Pressable>
        </View>

        {isLoading ? (
          <ActivityIndicator color={c.primary} style={styles.loading} />
        ) : (
          <View>
            {(lists ?? []).map((l) => {
              const contains = !!l.contains;
              return (
                <Pressable
                  key={l.id}
                  accessibilityRole="button"
                  accessibilityLabel={l.name}
                  accessibilityState={{ selected: contains }}
                  onPress={() => toggle(l.id, contains)}
                  style={({ pressed }) => [styles.row, pressed && styles.pressed]}
                >
                  <Ionicons
                    name={contains ? 'checkmark-circle' : 'ellipse-outline'}
                    size={24}
                    color={contains ? c.primary : c.placeholder}
                  />
                  <Text style={styles.rowName} numberOfLines={1}>
                    {l.name}
                  </Text>
                  <Text style={styles.count}>{t('lists.itemsCount', { count: l.items_count })}</Text>
                </Pressable>
              );
            })}

            {creating ? (
              <View style={styles.createRow}>
                <TextInput
                  style={styles.input}
                  value={newName}
                  onChangeText={setNewName}
                  placeholder={t('lists.namePlaceholder')}
                  placeholderTextColor={c.placeholder}
                  autoFocus
                  returnKeyType="done"
                  onSubmitEditing={createAndAdd}
                />
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={t('lists.create')}
                  onPress={createAndAdd}
                  style={styles.createBtn}
                >
                  <Text style={styles.createBtnText}>{t('lists.create')}</Text>
                </Pressable>
              </View>
            ) : (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('save.newList')}
                onPress={() => setCreating(true)}
                style={({ pressed }) => [styles.row, pressed && styles.pressed]}
              >
                <Ionicons name="add-circle-outline" size={24} color={c.primary} />
                <Text style={[styles.rowName, styles.newLabel]}>{t('save.newList')}</Text>
              </Pressable>
            )}
          </View>
        )}
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
      paddingBottom: 12,
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 10 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
    title: { fontFamily: fonts.display, fontSize: 19, fontWeight: '700', color: c.text },
    done: { color: c.primary, fontSize: 15, fontWeight: '700' },
    loading: { paddingVertical: 30 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14 },
    pressed: { opacity: 0.6 },
    rowName: { flex: 1, fontSize: 16, color: c.text },
    newLabel: { color: c.primary, fontWeight: '600' },
    count: { fontSize: 13, color: c.muted },
    createRow: { flexDirection: 'row', gap: 8, alignItems: 'center', paddingVertical: 10 },
    input: {
      flex: 1,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 14,
      paddingVertical: 12,
      fontSize: 15,
      color: c.text,
      backgroundColor: c.surface,
    },
    createBtn: { paddingHorizontal: 16, paddingVertical: 12, borderRadius: 12, borderWidth: 1.5, borderColor: c.primary },
    createBtnText: { color: c.primary, fontWeight: '700', fontSize: 15 },
  });
