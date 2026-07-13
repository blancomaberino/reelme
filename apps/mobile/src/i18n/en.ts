// English dictionary. Keys are flat dot-paths; `{{name}}` marks an interpolation
// slot. Plurals live as sibling `*_one` / `*_other` keys (see i18n/index.ts).
// Spanish (es.ts) is the DEFAULT app language and must mirror these keys exactly
// — the drift test in __tests__/i18n.test.ts fails the build if a key is missing.
export const en = {
  'common.tryAgain': 'Try again',

  'tabs.map': 'Map',
  'tabs.feed': 'Feed',
  'tabs.share': 'Share',
  'tabs.profile': 'Profile',

  'feed.title': 'Feed',
  'feed.search': 'Search',
  'feed.empty.title': 'Nothing here yet',
  'feed.empty.body': 'Share your first reel to see it on the feed.',
  'feed.error.title': 'Couldn’t load the feed',
  'feed.hide': 'Hide from my feed',
  'feed.hidden': 'Hidden from your feed',
  'feed.undo': 'Undo',
  'feed.sharerFallback': 'a Reelmap user',

  'place.back': 'Go back',
  'place.notFound.title': 'Place not found',
  'place.notFound.body': 'It may have been removed or the link is out of date.',
  'place.directions': 'Directions',
  'place.share': 'Share',
  'place.shareMessage': '{{name}} on Reelmap',
  'place.dishes': 'Dishes',
  'place.sources': 'Where it came from',
  'place.reviews': 'Reviews',
  'place.fromGoogle': 'From Google',
  'place.you': ' (you)',
  'place.anonymous': 'anonymous',
  'place.googleUser': 'Google user',
  'place.sourceCount': '{{count}} sources',
  'place.sourceCount_one': '{{count}} source',
  'place.sourceCount_other': '{{count}} sources',
  'place.hoursShow': 'Show weekly hours',
  'place.hoursHide': 'Hide weekly hours',
  'place.openInMap': 'Open in map',
  'place.website': 'Open website',
  'place.call': 'Call {{phone}}',
  'place.view': 'View place',

  'source.openOriginal': 'Open original {{platform}} post',
  'source.firstShared': 'First shared',

  'filter.following': 'Following',

  'search.placeholder': 'Search places, tags…',
  'search.cancel': 'Cancel',
  'search.close': 'Close',
  'search.clear': 'Clear',
  'search.hint': 'Type at least 2 characters to search.',
  'search.error': 'Something went wrong. Try again.',
  'search.noResults': 'No results for “{{query}}”.',
  'search.section.places': 'Places',
  'search.section.tags': 'Tags',
  'search.section.influencers': 'Influencers',
  'search.profilesSoon': 'Profiles coming soon',

  'map.search': 'Search',
  'map.zoomIn': 'Zoom in for more places',

  'profile.title': 'Profile',
  'profile.note': 'Your shares, followers & settings land here (T-039).',
  'profile.settings': 'Settings',
  'profile.logout': 'Log out',

  'share.title': 'Share',
  'share.subtitle': 'Paste a URL or use the OS share sheet (T-025)',

  'settings.title': 'Settings',
  'settings.language': 'Language',
  'settings.languageHint': 'Choose the language of the app.',
  'settings.language.es': 'Español',
  'settings.language.en': 'English',

  'auth.welcome.tagline': 'Share a food video. Pin the place. Discover where the internet eats.',
  'auth.welcome.createAccount': 'Create account',
  'auth.welcome.login': 'Log in',
  'auth.welcome.legal': 'By continuing you agree to our Terms & Privacy Policy.',

  'auth.field.name': 'Name',
  'auth.field.username': 'Username',
  'auth.field.email': 'Email',
  'auth.field.password': 'Password',

  'auth.login.title': 'Welcome back',
  'auth.login.subtitle': 'Log in to pick up where you left off.',
  'auth.login.submit': 'Log in',
  'auth.login.newHere': 'New here? ',
  'auth.login.createAccount': 'Create an account',

  'auth.register.title': 'Create your account',
  'auth.register.subtitle': 'Save the places behind every food video you love.',
  'auth.register.submit': 'Create account',
  'auth.register.haveAccount': 'Already have an account? ',
  'auth.register.login': 'Log in',
} as const;

export type MessageKey = keyof typeof en;
