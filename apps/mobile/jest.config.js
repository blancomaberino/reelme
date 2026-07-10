/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|@expo/.*|expo-modules-core|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|react-native-.*|expo-router|expo-.*|axios))',
  ],
};
