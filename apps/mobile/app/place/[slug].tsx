import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Alert, Pressable, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { usePlace } from '@/api/hooks/usePlace';
import type { PlaceDetail } from '@/api/places';
import { Chip } from '@/components/place/chip';
import { Button } from '@/components/button';
import { OfferCard } from '@/components/offer/offer-card';
import { MiniMap } from '@/components/place/mini-map';
import { ReviewComposer } from '@/components/place/review-composer';
import { MenuSheet } from '@/components/place/menu-sheet';
import { ReportSheet } from '@/components/report-sheet';
import { MyTags } from '@/components/place/my-tags';
import { PlaceGallery } from '@/components/place/place-gallery';
import { ReviewSources } from '@/components/place/review-sources';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { SourceCard } from '@/components/place/source-card';
import { SuggestEditSheet } from '@/components/place/suggest-edit-sheet';
import { Thumbnail } from '@/components/place/thumbnail';
import { Skeleton, SkeletonGroup } from '@/components/skeleton';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { hourLines, openStateLabel } from '@/lib/opening-hours';
import { directionsUrl, googleMapsUrl, googleReviewsUrl, placeShareUrl } from '@/lib/directions';
import { openExternal, openWebUrl } from '@/lib/linking';
import { useSessionStore } from '@/stores/session';
import { fonts, type Palette, useColors } from '@/theme/colors';

export default function PlaceDetailScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  // usePlace polls while the gallery is empty (a just-shared place enriches
  // async), so the carousel fills in on its own — no route flag needed.
  const { data: place, isLoading, isError, refetch } = usePlace(slug ?? '');
  const authed = useSessionStore((s) => s.status === 'authed');
  const [saveOpen, setSaveOpen] = useState(false);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <Header
        onBack={() => (router.canGoBack() ? router.back() : router.replace('/(main)/map'))}
        onSave={authed && place ? () => setSaveOpen(true) : undefined}
        styles={styles}
        c={c}
      />
      {isLoading ? (
        <PlaceSkeleton styles={styles} authed={authed} />
      ) : isError || !place ? (
        <ErrorState styles={styles} c={c} onRetry={() => void refetch()} />
      ) : (
        <PlaceBody place={place} authed={authed} styles={styles} c={c} />
      )}
      {place ? <SaveToListSheet placeId={place.id} visible={saveOpen} onClose={() => setSaveOpen(false)} /> : null}
    </SafeAreaView>
  );
}

function Header({
  onBack,
  onSave,
  styles,
  c,
}: {
  onBack: () => void;
  onSave?: () => void;
  styles: Styles;
  c: Palette;
}) {
  const t = useT();
  return (
    <View style={styles.header}>
      <Pressable accessibilityRole="button" accessibilityLabel={t('common.back')} onPress={onBack} hitSlop={12}>
        <Ionicons name="chevron-back" size={26} color={c.text} />
      </Pressable>
      {onSave ? (
        <Pressable accessibilityRole="button" accessibilityLabel={t('save.title')} onPress={onSave} hitSlop={12}>
          <Ionicons name="bookmark-outline" size={24} color={c.text} />
        </Pressable>
      ) : null}
    </View>
  );
}

function PlaceBody({ place, authed, styles, c }: { place: PlaceDetail; authed: boolean; styles: Styles; c: Palette }) {
  const t = useT();
  const fmt = useFormat();
  const [hoursOpen, setHoursOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [suggestOpen, setSuggestOpen] = useState(false);
  // Which review's report sheet is open, null when none. Holds the SUBJECT too
  // so the sheet can name what is being flagged — the whole point of showing it
  // back is that nobody reports the wrong row.
  const [reportReview, setReportReview] = useState<{ id: string; subject: string } | null>(null);
  const hours = useMemo(() => hourLines(place.opening_hours), [place.opening_hours]);
  const openState = useMemo(() => openStateLabel(place.open_state), [place.open_state]);
  const tags = useMemo(
    () => Array.from(new Set([...place.cuisines, ...place.vibe_tags, ...place.dietary_tags])),
    [place.cuisines, place.vibe_tags, place.dietary_tags],
  );
  // Hero picture: prefer the curated business image (T-084), then the reel
  // poster (primary source, else the first).
  const heroUri = useMemo(() => {
    if (place.image_url) return place.image_url;
    const s = place.sources?.find((x) => x.is_primary) ?? place.sources?.[0];
    return s?.source_post?.thumbnail_url ?? null;
  }, [place.image_url, place.sources]);
  // A place with more than one business photo (T-099) shows a swipeable gallery
  // in place of the single hero; one or zero photos keeps the hero.
  const gallery = place.gallery ?? [];
  const appReviews = place.reviews ?? [];
  const googleReviews = place.google_reviews ?? [];
  // The viewer's own review (prefills the composer); listed rows exclude it.
  const ownReview = appReviews.find((r) => r.is_own) ?? null;
  const otherReviews = appReviews.filter((r) => !r.is_own);

  const openMap = () =>
    router.push({ pathname: '/(main)/map', params: { lat: String(place.lat), lng: String(place.lng) } });

  const openDirections = () => void openExternal(directionsUrl(place.lat, place.lng, place.name));

  const share = () =>
    void Share.share({ message: t('place.shareMessage', { name: place.name }), url: placeShareUrl(place.slug) });

  return (
    <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
      {/* Business photos: a swipeable gallery when there's more than one (T-099),
          else the single hero (curated image → reel poster). */}
      {gallery.length > 1 ? (
        <PlaceGallery images={gallery} testID="place-gallery" />
      ) : heroUri ? (
        <Thumbnail uri={heroUri} style={styles.hero} testID="place-hero" />
      ) : null}

      {/* Header block */}
      <View style={styles.block}>
        <Text style={styles.name}>{place.name}</Text>
        <View style={styles.metaRow}>
          {fmt.priceLine(place.category, place.price_range) ? (
            <Text style={styles.meta}>{fmt.priceLine(place.category, place.price_range)}</Text>
          ) : null}
          {place.rating.google.value != null ? (
            <Text style={styles.rating}>
              <Ionicons name="star" size={13} color={c.gold} /> {place.rating.google.value.toFixed(1)}
              {place.rating.google.count > 0 ? ` (${place.rating.google.count})` : ''}
            </Text>
          ) : null}
          <Text style={styles.metaMuted}>
            {t('place.sourceCount', { count: place.source_count })}
          </Text>
        </View>
        {tags.length > 0 ? (
          <View style={styles.chips}>
            {tags.map((tag) => (
              <Chip key={tag} label={fmt.tag(tag)} />
            ))}
          </View>
        ) : null}
      </View>

      {/* My tags — private, owner-only annotations (T-064) */}
      {authed ? <MyTags slug={place.slug} tags={place.my_tags ?? []} /> : null}

      {/* Live Reelmap offers (T-047). Placed directly ABOVE the card discounts
          so the two "what you can save here" blocks read as one idea, with the
          actionable, time-boxed one first — a card discount is a standing fact,
          an offer is a thing you claim before you walk in. Only ever `active`
          ones: the API filters the embed, so this section cannot advertise a
          promotion the till would refuse. */}
      {place.offers && place.offers.length > 0 ? (
        <View style={styles.block} testID="place-offers">
          <Text style={styles.sectionTitle}>{t('place.offers')}</Text>
          {place.offers.map((offer) => (
            <OfferCard
              key={offer.id}
              offer={offer}
              // No venue name — you are standing on the venue's page.
              onPress={() => router.push({ pathname: '/offers/[id]/redeem', params: { id: offer.id } })}
              actions={
                <Button
                  title={t('offers.browse.getCode')}
                  size="sm"
                  onPress={() => router.push({ pathname: '/offers/[id]/redeem', params: { id: offer.id } })}
                  testID={`place-offer-cta-${offer.id}`}
                />
              }
            />
          ))}
        </View>
      ) : null}

      {/* Card/bank/wallet payment discounts mentioned in the reels (T-079) */}
      {place.discounts.length > 0 ? (
        <View style={styles.block}>
          <Text style={styles.sectionTitle}>{t('place.discounts')}</Text>
          <View style={styles.chips}>
            {place.discounts.map((d) => (
              <View key={`${d.card}-${d.terms}`} style={styles.discount}>
                <Text style={styles.discountText}>
                  💳 {d.card} · {d.terms}
                </Text>
              </View>
            ))}
          </View>
        </View>
      ) : null}

      {/* Info: address / hours / phone / website */}
      <View style={styles.block}>
        {place.address ? (
          <Row icon="location-outline" c={c} styles={styles}>
            <Text style={styles.rowText}>{place.address}</Text>
          </Row>
        ) : null}

        {/* Opening hours (T-128). Gated on the LINES, not on a summary label —
            the old gate was `hours.label`, a summary the function never
            produced for the flat string list the API actually sends, so this
            row had never once rendered for anyone. There is no open/closed
            badge, and that is deliberate — see `hourLines` for why claiming it
            from this text would be a guess.

            Why COLLAPSED, given it is then the only row here that shows no
            data until tapped — the alternative was weighed, not overlooked.
            Expanded-by-default costs seven rows in the middle of a dense info
            block, pushing phone, website and directions below the fold on a
            screen people open to decide whether to go NOW; and there is no
            honest one-line summary to put in the collapsed row instead,
            because "today's line" cannot be identified (weekday_text is
            ordered by the SOURCE locale's first day of week, so no index is a
            fixed weekday). One tap to seven correct lines beats a permanent
            seven-row block or a summary that would be a guess.

            T-155 met that condition: the API now serves `open_state`, computed
            from structured periods and the venue's own timezone, so the
            collapsed row CAN carry an honest status. It is still not parsed
            from these lines — the cue is the server's answer, rendered. When
            `open_state` is null the row reads exactly as it did before: the
            label alone, and no claim. */}
        {hours.length > 0 ? (
          <>
            <Pressable
              onPress={() => setHoursOpen((v) => !v)}
              accessibilityRole="button"
              // The status cue must be IN this label, not merely rendered inside
              // the row. A Pressable with an accessibilityRole + label collapses
              // into ONE accessibility element on iOS and its children vanish
              // from the tree — so a cue left as a child is invisible to
              // VoiceOver (and to Maestro, which reads the same tree). Someone
              // using a screen reader to decide whether to go now would hear
              // only "show weekly hours" and never the answer.
              //
              // The middle dot is swapped for a comma HERE ONLY. VoiceOver reads
              // `·` aloud as "middle dot", so the announced name would be
              // "Abierto middle dot cierra 23:00" — the right information,
              // delivered badly. The visual string keeps the dot.
              accessibilityLabel={[
                openState ? t(openState.key, openState.vars).replace(' · ', ', ') : null,
                hoursOpen ? t('place.hoursHide') : t('place.hoursShow'),
              ]
                .filter(Boolean)
                .join(', ')}
              accessibilityState={{ expanded: hoursOpen }}
              testID="place-hours"
            >
              <Row icon="time-outline" c={c} styles={styles}>
                <Text style={styles.rowText}>{t('place.hours')}</Text>
                {/* A SIBLING of the label, not nested inside it: nested text
                    collapses into one node, which would fold the neutral label
                    and the status claim into a single string for a screen
                    reader and for any test reading the row. */}
                {openState ? (
                  <Text style={openState.open ? styles.openCue : styles.closedCue}>
                    {t(openState.key, openState.vars)}
                  </Text>
                ) : null}
                <Ionicons
                  name={hoursOpen ? 'chevron-up' : 'chevron-down'}
                  size={16}
                  color={c.muted}
                  style={styles.chevron}
                />
              </Row>
            </Pressable>
            {hoursOpen ? (
              <View style={styles.weekly} testID="place-hours-weekly">
                {/* Keyed by index: two days can legitimately carry the same
                    text ("Monday: Closed", "Sunday: Closed"), and keying by
                    the line would collide and drop one of them. */}
                {hours.map((line, i) => (
                  <Text key={`${i}-${line}`} style={styles.weeklyLine}>
                    {line}
                  </Text>
                ))}
              </View>
            ) : null}
          </>
        ) : null}

        {place.phone ? (
          <Pressable
            onPress={() => void openExternal(`tel:${place.phone}`)}
            accessibilityRole="button"
            accessibilityLabel={t('place.call', { phone: place.phone })}
          >
            <Row icon="call-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]}>{place.phone}</Text>
            </Row>
          </Pressable>
        ) : null}

        {place.website ? (
          <Pressable onPress={() => openWebUrl(place.website)} accessibilityRole="link" accessibilityLabel={t('place.website')}>
            <Row icon="globe-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]} numberOfLines={1}>
                {place.website.replace(/^https?:\/\//, '')}
              </Text>
            </Row>
          </Pressable>
        ) : null}

        {googleMapsUrl(place.name, place.google_place_id) ? (
          <Pressable
            onPress={() => openWebUrl(googleMapsUrl(place.name, place.google_place_id))}
            accessibilityRole="link"
            accessibilityLabel={t('place.googleMaps')}
          >
            <Row icon="map-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]}>{t('place.googleMaps')}</Text>
            </Row>
          </Pressable>
        ) : null}

        {/* Correct what is above (T-083). Sits at the FOOT of the info block,
            attached by a hairline, because it is about those five lines and
            nothing else — as a button in the action row it would have competed
            with "Directions" and read as something you do to the venue rather
            than to the listing. Signed-in only: the endpoint needs an account,
            and a control that always answers "sign in first" is a dead end.
            Verified operators get the same row with the owner's wording; the
            server, not this label, decides whether it applies or queues. */}
        {authed ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={place.can_edit ? t('suggest.title.owner') : t('suggest.entry')}
            onPress={() => setSuggestOpen(true)}
            testID="place-suggest-edit"
            style={({ pressed }) => [styles.suggestRow, pressed && styles.suggestRowPressed]}
          >
            <Ionicons name={place.can_edit ? 'create-outline' : 'help-circle-outline'} size={17} color={c.muted} />
            <Text style={styles.suggestText}>{place.can_edit ? t('suggest.title.owner') : t('suggest.entry')}</Text>
            <Ionicons name="chevron-forward" size={16} color={c.muted} />
          </Pressable>
        ) : null}
      </View>

      {/* Mini-map */}
      <View style={styles.block}>
        <MiniMap place={place} onPress={openMap} />
      </View>

      {/* Actions */}
      <View style={styles.actions}>
        <ActionButton icon="navigate" label={t('place.directions')} onPress={openDirections} c={c} styles={styles} />
        <ActionButton icon="share-outline" label={t('place.share')} onPress={share} c={c} styles={styles} />
        {/* Report sits in the primary action row, not behind an overflow menu:
            Apple 1.2 reviewers look for a VISIBLE report path on user-generated
            content, and a control nobody can find is one that does not exist. */}
        <ActionButton
          icon="flag-outline"
          label={t('report.action')}
          onPress={() => setReportOpen(true)}
          c={c}
          styles={styles}
          testID="place-report"
        />
      </View>

      {/* Menu — a button into the full dish/price list + its source & date */}
      {place.dishes.length > 0 ? (
        <View style={styles.block}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('menu.view')}
            onPress={() => setMenuOpen(true)}
            style={({ pressed }) => [styles.menuButton, pressed && styles.menuButtonPressed]}
          >
            <Ionicons name="restaurant-outline" size={20} color={c.primary} />
            <Text style={styles.menuButtonText}>{t('menu.view')}</Text>
            <Text style={styles.menuCount}>{t('place.dishesCount', { count: place.dishes.length })}</Text>
            <Ionicons name="chevron-forward" size={18} color={c.muted} />
          </Pressable>
        </View>
      ) : null}

      {/* Sources */}
      {place.sources && place.sources.length > 0 ? (
        <View style={styles.block}>
          <Text style={styles.sectionTitle}>{t('place.sources')}</Text>
          <View style={styles.sourceList}>
            {place.sources.map((s) => (
              <SourceCard key={s.id} source={s} />
            ))}
          </View>
        </View>
      ) : null}

      {/* Ratings across the web: per-source summary rows (T-082) */}
      <ReviewSources sources={place.review_sources ?? []} />

      {/* Reviews: your composer, then in-app + Google (with reviewer photos) */}
      <View style={styles.block}>
        <Text style={styles.sectionTitle}>{t('place.reviews')}</Text>
        <ReviewComposer placeId={place.id} slug={place.slug} own={ownReview} />
        {otherReviews.map((r) => (
          <ReviewRow
            key={`a-${r.id}`}
            name={r.author ? `@${r.author.username}` : t('place.anonymous')}
            rating={r.rating}
            text={r.body}
            onReport={() => setReportReview({ id: String(r.id), subject: r.author ? `@${r.author.username}` : t('place.anonymous') })}
            // NOT a bare "Report": the place's own report control is on the
            // same screen with that exact label, so a screen-reader user met
            // two identical buttons doing different things.
            reportLabel={t('report.reviewAction', {
              name: r.author ? `@${r.author.username}` : t('place.anonymous'),
            })}
            c={c}
            styles={styles}
          />
        ))}
        {googleReviews.length > 0 ? (
            <>
              <Text style={styles.reviewSub}>{t('place.fromGoogle')}</Text>
              {googleReviews.map((r, i) => (
                <ReviewRow
                  key={`g-${i}`}
                  name={r.author ?? t('place.googleUser')}
                  suffix={r.relative_time ? ` · ${r.relative_time}` : undefined}
                  rating={r.rating}
                  text={r.text}
                  photo={r.profile_photo_url}
                  c={c}
                  styles={styles}
                />
              ))}
            </>
          ) : null}
          {googleReviewsUrl(place.google_place_id) ? (
            <Pressable
              onPress={() => openWebUrl(googleReviewsUrl(place.google_place_id))}
              accessibilityRole="link"
              accessibilityLabel={t('place.readOnGoogle')}
              style={styles.readOnGoogle}
            >
              <Ionicons name="logo-google" size={15} color={c.primary} />
              <Text style={[styles.rowText, styles.link]}>{t('place.readOnGoogle')}</Text>
              <Ionicons name="open-outline" size={15} color={c.primary} />
            </Pressable>
          ) : null}
        </View>

      <View style={styles.footer} />

      <MenuSheet
        visible={menuOpen}
        onClose={() => setMenuOpen(false)}
        dishes={place.dishes}
        updatedAt={place.dishes_updated_at}
        language={place.dishes_language}
        sources={place.sources ?? []}
      />

      <SuggestEditSheet
        visible={suggestOpen}
        onClose={() => setSuggestOpen(false)}
        place={place}
        // The receipt for a QUEUED proposal only. An operator's edit needs none:
        // it has already landed on the screen behind the sheet.
        onQueued={() => Alert.alert(t('suggest.queued.title'), t('suggest.queued.body'))}
      />

      <ReportSheet
        visible={reportOpen}
        onClose={() => setReportOpen(false)}
        target={{ type: 'place', id: String(place.id) }}
        subject={place.name}
      />

      {/* A SECOND instance rather than one sheet with a swapped target: the
          sheet resets its state on the visible transition, and reusing one
          would carry the place-report's chosen reason into a review report —
          whose reasons are a different set entirely. Cheap: it renders nothing
          until a review's flag is tapped. */}
      <ReportSheet
        visible={reportReview !== null}
        onClose={() => setReportReview(null)}
        target={{ type: 'review', id: reportReview?.id ?? '' }}
        subject={reportReview?.subject ?? ''}
      />
    </ScrollView>
  );
}

function Row({
  icon,
  c,
  styles,
  children,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  c: Palette;
  styles: Styles;
  children: React.ReactNode;
}) {
  return (
    <View style={styles.row}>
      <Ionicons name={icon} size={18} color={c.muted} style={styles.rowIcon} />
      <View style={styles.rowBody}>{children}</View>
    </View>
  );
}

function ActionButton({
  icon,
  label,
  onPress,
  c,
  styles,
  testID,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  c: Palette;
  styles: Styles;
  testID?: string;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      testID={testID}
      style={({ pressed }) => [styles.action, pressed ? styles.actionPressed : null]}
    >
      <Ionicons name={icon} size={20} color={c.onPrimary} />
      <Text style={styles.actionLabel}>{label}</Text>
    </Pressable>
  );
}

function ReviewRow({
  name,
  suffix,
  rating,
  text,
  photo,
  onReport,
  reportLabel,
  c,
  styles,
}: {
  name: string;
  suffix?: string;
  rating: number | null;
  text: string | null;
  photo?: string | null;
  /** Native reviews only — Google's are not our UGC and have no id here. */
  onReport?: () => void;
  reportLabel?: string;
  c: Palette;
  styles: Styles;
}) {
  const stars = rating != null ? '★'.repeat(Math.max(0, Math.min(5, Math.round(rating)))) : '';
  return (
    <View style={styles.review}>
      {photo ? (
        <Thumbnail uri={photo} style={styles.reviewAvatar} />
      ) : (
        <View style={[styles.reviewAvatar, styles.reviewAvatarFallback]}>
          <Text style={styles.reviewInitial}>{name.replace(/^@/, '').charAt(0).toUpperCase() || '?'}</Text>
        </View>
      )}
      <View style={styles.reviewBody}>
        <Text style={styles.reviewName} numberOfLines={1}>
          {name}
          {suffix} <Text style={styles.reviewStars}>{stars}</Text>
        </Text>
        {text ? <Text style={styles.reviewText}>{text}</Text> : null}
      </View>
      {/* A review is user-generated content, so Apple 1.2 wants a report path on
          it — and the endpoint has existed since T-059 with nothing calling it.
          Only for NATIVE reviews: a Google review is not our UGC, has no id in
          our system, and the endpoint would 404. */}
      {onReport ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={reportLabel}
          onPress={onReport}
          hitSlop={10}
          style={({ pressed }) => [styles.reviewReport, pressed ? styles.actionPressed : null]}
        >
          <Ionicons name="flag-outline" size={15} color={c.muted} />
        </Pressable>
      ) : null}
    </View>
  );
}

/**
 * Stands in for {@link PlaceBody} above the fold (T-108). Every block matches
 * the real element's height, so landing content settles in place instead of
 * shoving the page around: hero 190, title 27pt, three info rows at 33, the
 * 160pt mini-map, and the 48pt action pair.
 *
 * `authed` is not decoration: the my-tags block only renders for a signed-in
 * viewer, and leaving it out shifted everything below the chips by ~90pt on the
 * exact screens most people see.
 *
 * Scrollable for the same reason {@link PlaceBody} is: signed in, this comes to
 * ~835pt, which overflows a 4.7" viewport — as a plain View the mini-map and the
 * action pair were simply clipped, and the placeholder stopped describing the
 * page it stands in for.
 */
function PlaceSkeleton({ styles, authed }: { styles: Styles; authed: boolean }) {
  return (
    <ScrollView showsVerticalScrollIndicator={false} testID="place-skeleton-scroll">
      <SkeletonGroup style={styles.scroll} testID="place-skeleton">
        <Skeleton height={190} shape="block" style={styles.skelHero} />

        {/* Name, meta line, tag chips */}
        <View style={styles.block}>
          <Skeleton height={26} width="72%" />
          <Skeleton height={15} width="46%" />
          <View style={styles.chips}>
            <Skeleton height={28} width={86} />
            <Skeleton height={28} width={64} />
            <Skeleton height={28} width={74} />
          </View>
        </View>

        {/* My tags: heading, hint, and the add-a-tag field */}
        {authed ? (
          <View style={styles.block}>
            <Skeleton height={20} width="42%" />
            <Skeleton height={14} width="62%" />
            <Skeleton height={44} shape="block" />
          </View>
        ) : null}

        {/* Address / hours / phone — icon plus its line */}
        <View style={styles.block}>
          {(['82%', '54%', '64%'] as const).map((w) => (
            <View key={w} style={styles.skelRow}>
              <Skeleton height={18} shape="circle" />
              <Skeleton height={15} width={w} />
            </View>
          ))}
        </View>

        <Skeleton height={160} shape="block" />

        <View style={styles.actions}>
          <Skeleton height={48} shape="block" style={styles.skelAction} />
          <Skeleton height={48} shape="block" style={styles.skelAction} />
        </View>
      </SkeletonGroup>
    </ScrollView>
  );
}

function ErrorState({ styles, c, onRetry }: { styles: Styles; c: Palette; onRetry: () => void }) {
  const t = useT();
  return (
    <View style={styles.center}>
      <Ionicons name="sad-outline" size={40} color={c.muted} />
      <Text style={styles.errorTitle}>{t('place.notFound.title')}</Text>
      <Text style={styles.errorBody}>{t('place.notFound.body')}</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} style={styles.retry}>
        <Text style={styles.retryText}>{t('common.tryAgain')}</Text>
      </Pressable>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 8 },
    scroll: { padding: 20, gap: 20 },
    block: { gap: 10 },
    name: { fontFamily: fonts.display, fontSize: 27, fontWeight: '700', letterSpacing: -0.4, color: c.text },
    metaRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 12 },
    meta: { fontSize: 15, color: c.text, textTransform: 'capitalize' },
    metaMuted: { fontSize: 14, color: c.muted },
    rating: { fontSize: 14, color: c.text, fontWeight: '600' },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    discount: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 999,
      backgroundColor: c.secondarySoft,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.secondary,
    },
    discountText: { color: c.secondary, fontSize: 13, fontWeight: '700' },
    row: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, paddingVertical: 6 },
    rowIcon: { marginTop: 1 },
    rowBody: { flex: 1, flexDirection: 'row', alignItems: 'center' },
    rowText: { flex: 1, fontSize: 15, color: c.text, lineHeight: 21 },
    // Semantic, not decorative: the two states must be distinguishable without
    // reading, and without relying on colour alone — the words differ too.
    openCue: { color: c.green, fontWeight: '600' },
    closedCue: { color: c.muted, fontWeight: '600' },
    chevron: { marginLeft: 8 },
    weekly: { paddingLeft: 30, gap: 4, paddingBottom: 4 },
    weeklyLine: { fontSize: 14, color: c.muted },
    link: { color: c.primary },
    // The "something here is wrong" row: attached to the info block by a
    // hairline and muted throughout, so it is findable without competing with
    // the primary actions below it.
    suggestRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingTop: 12,
      marginTop: 4,
      borderTopWidth: StyleSheet.hairlineWidth,
      borderTopColor: c.border,
    },
    suggestRowPressed: { opacity: 0.6 },
    suggestText: { flex: 1, fontSize: 14, color: c.muted, fontWeight: '600' },
    actions: { flexDirection: 'row', gap: 12 },
    action: {
      flex: 1,
      flexDirection: 'row',
      gap: 8,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primary,
      borderRadius: 14,
      paddingVertical: 14,
    },
    actionPressed: { backgroundColor: c.primaryPressed },
    actionLabel: { color: c.onPrimary, fontSize: 15, fontWeight: '600' },
    hero: { width: '100%', height: 190, borderRadius: 16, marginBottom: 4 },
    sectionTitle: { fontFamily: fonts.display, fontSize: 19, fontWeight: '700', color: c.text, letterSpacing: -0.2 },
    updatedAt: { fontSize: 12, color: c.muted, marginTop: 6 },
    menuButton: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 14,
      paddingHorizontal: 16,
      borderRadius: 14,
      borderWidth: 1.5,
      borderColor: c.primary,
      backgroundColor: c.primarySoft,
    },
    menuButtonPressed: { opacity: 0.7 },
    menuButtonText: { flex: 1, fontSize: 16, fontWeight: '700', color: c.primary },
    menuCount: { fontSize: 13, color: c.muted },
    reviewSub: { fontFamily: fonts.display, fontSize: 15, fontWeight: '700', color: c.ink2, marginTop: 8, marginBottom: 2 },
    review: { flexDirection: 'row', gap: 10, paddingVertical: 8 },
    reviewAvatar: { width: 36, height: 36, borderRadius: 18 },
    reviewAvatarFallback: { backgroundColor: c.secondarySoft, alignItems: 'center', justifyContent: 'center' },
    reviewInitial: { color: c.secondary, fontWeight: '700', fontSize: 15 },
    reviewBody: { flex: 1, gap: 3 },
    reviewName: { fontSize: 14, color: c.text, fontWeight: '600' },
    reviewStars: { color: c.gold },
    reviewReport: { paddingLeft: 8, paddingTop: 4 },
    reviewText: { fontSize: 14, color: c.ink2, lineHeight: 19 },
    readOnGoogle: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 10, marginTop: 2 },
    sourceList: { gap: 12 },
    footer: { height: 24 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, gap: 8 },
    errorTitle: { fontSize: 20, fontWeight: '700', color: c.text, marginTop: 8 },
    errorBody: { fontSize: 15, color: c.muted, textAlign: 'center' },
    retry: {
      marginTop: 12,
      paddingHorizontal: 20,
      paddingVertical: 10,
      borderRadius: 12,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    retryText: { color: c.primary, fontWeight: '600', fontSize: 15 },
    // Skeleton geometry that mirrors `hero`'s trailing gap, the info `row`, and
    // an `action` sharing the row equally.
    skelHero: { marginBottom: 4 },
    skelRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 6 },
    skelAction: { flex: 1 },
  });
