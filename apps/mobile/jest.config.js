/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
  // jest-expo's babel/RN transform is heavy; on shared CI runners several
  // suites contend for 2 cores and a single async `waitFor` test can exceed
  // the 5s default (seen on CI, never locally). 15s gives headroom without
  // hiding a genuine hang.
  testTimeout: 15000,
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|@expo/.*|expo-modules-core|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|react-native-.*|expo-router|expo-.*|axios))',
  ],
};
